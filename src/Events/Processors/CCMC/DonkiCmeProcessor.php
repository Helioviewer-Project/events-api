<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\CCMC;

use Helioviewer\EventsApi\Events\Processors\BaseProcessor;
use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use Helioviewer\EventsApi\Exception\CoordinateResolutionException;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;
use Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI\GifCollector as DonkiGifCollector;
use HelioviewerEventInterface\Util\LocationParser;
use Psr\Log\LoggerInterface;

/**
 * DONKI Coronal Mass Ejection (CME) Event Processor
 *
 * This processor handles the transformation and normalization of DONKI CME event data
 * from the Community Coordinated Modeling Center (CCMC). It processes raw CME data
 * from the DONKI (Database Of Notifications, Knowledge, Information) service and
 * converts it into the standardized Event model format used by the Helioviewer
 * Events API.
 *
 * The processor handles:
 * - CME activity identification and validation
 * - Coordinate transformation from DONKI format to Helioviewer coordinate system
 * - Event timeline processing (start, peak, end times)
 * - Data deduplication based on remote ID and source
 * - Legacy compatibility mapping for existing Helioviewer event types
 *
 * Data Sources Supported:
 * - DONKI CME analysis data via CCMC API
 * - Real-time and historical CME event records
 * - CME parameter data including speed, direction, and associated phenomena
 *
 * Coordinate System Transformations:
 * - Reads the source location (e.g. N10W20) as Stonyhurst coordinates
 * - Maintains compatibility with legacy Helioviewer coordinate representation
 * - Preserves original DONKI coordinate metadata for reference
 *
 * @package    Helioviewer\EventsApi\Collector\Processors\CCMC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 * @see        https://ccmc.gsfc.nasa.gov/DONKI/
 * @see        EventProcessorInterface
 */
class DonkiCmeProcessor extends BaseProcessor
{
    public function __construct(
        ?LoggerInterface $logger = null,
        ?SentryClientInterface $sentry = null,
        private ?DonkiGifCollector $donkiGifCollector = null
    ) {
        parent::__construct($logger, $sentry);
    }

    /**
     * The multi-line label, kept to event-interface's DonkiCme rules: the most
     * accurate analysis' type, half angle and speed (else the start date), plus
     * "Modeled" when that analysis has model runs.
     */
    private function label(array $rawRecord, int $startTime): string
    {
        $analysis = $this->mostAccurateAnalysis($rawRecord);
        if (!$this->hasLocation($rawRecord, $analysis)) {
            return 'CME ' . ($rawRecord['activityID'] ?? '');
        }
        if (!$analysis) {
            return gmdate('Y-m-d H:i:s', $startTime);
        }

        return "Type: {$analysis['type']}\nHalf Angle: {$analysis['halfAngle']}&deg;\n{$analysis['speed']} km/s"
            . (empty($analysis['enlilList']) ? '' : "\nModeled");
    }

    /**
     * The one-line label, with the same rules as label().
     */
    private function shortLabel(array $rawRecord, int $startTime): string
    {
        $analysis = $this->mostAccurateAnalysis($rawRecord);
        if (!$this->hasLocation($rawRecord, $analysis)) {
            return 'CME';
        }
        if (!$analysis) {
            return gmdate('Y-m-d H:i:s', $startTime);
        }

        return "Type: {$analysis['type']} {$analysis['halfAngle']}&deg; {$analysis['speed']} km/s"
            . (empty($analysis['enlilList']) ? '' : ' Modeled');
    }

    /**
     * Whether the CME can be placed: the most accurate analysis' latitude and
     * longitude, or without one, a source location. Without it, both labels
     * fall back to plain "CME" text.
     */
    private function hasLocation(array $rawRecord, ?array $analysis): bool
    {
        return $analysis
            ? isset($analysis['latitude'], $analysis['longitude'])
            : trim((string) ($rawRecord['sourceLocation'] ?? '')) !== '';
    }

    /**
     * The first analysis marked most accurate. Labels use this one, unlike
     * selectBestAnalysis(), which prefers the leading edge.
     */
    private function mostAccurateAnalysis(array $rawRecord): ?array
    {
        foreach (($rawRecord['cmeAnalyses'] ?? []) as $analysis) {
            if (!empty($analysis['isMostAccurate'])) {
                return $analysis;
            }
        }

        return null;
    }

    /**
     * Get the best CME analysis, preferring Leading Edge (LE) with mostAccurate
     * 
     * @param array $cmeAnalyses Array of CME analyses from DONKI
     * @return array|null The selected analysis or null if none available
     */
    private function selectBestAnalysis(array $cmeAnalyses): ?array
    {
        if (empty($cmeAnalyses)) {
            return null;
        }
        
        // First try: mostAccurate=true AND featureCode=LE
        $bestAnalyses = array_filter($cmeAnalyses, function($analysis) {
            return isset($analysis['isMostAccurate']) && $analysis['isMostAccurate'] === true
                && isset($analysis['featureCode']) && $analysis['featureCode'] === 'LE';
        });
        
        if (!empty($bestAnalyses)) {
            return array_values($bestAnalyses)[0];
        }
        
        // Second try: just mostAccurate=true
        $mostAccurate = array_filter($cmeAnalyses, function($analysis) {
            return isset($analysis['isMostAccurate']) && $analysis['isMostAccurate'] === true;
        });
        
        if (!empty($mostAccurate)) {
            return array_values($mostAccurate)[0];
        }
        
        // No mostAccurate found
        return null;
    }
    
    /**
     * Calculate CME arrival time at Earth using ballistic propagation
     * 
     * This method calculates when a CME will reach Earth (1 AU) based on its
     * speed at 21.5 solar radii. It uses simple ballistic propagation assuming
     * constant velocity from 21.5 Rs to 1 AU.
     * 
     * Physics:
     * - 1 AU (Astronomical Unit) ≈ 215 solar radii
     * - 1 solar radius ≈ 696,000 km
     * - Distance from 21.5 Rs to Earth = (215 - 21.5) × 696,000 km
     * - Travel time = Distance / Speed
     * 
     * @param float $time21_5_timestamp Unix timestamp when CME reached 21.5 Rs
     * @param float $speedKmPerSec CME speed in km/s at 21.5 Rs
     * @return int Unix timestamp of predicted Earth arrival
     */
    private function calculateBallisticArrivalTime(float $time21_5_timestamp, float $speedKmPerSec): int
    {
        // Constants
        $solarRadiusKm = 696000;          // Solar radius in kilometers
        $earthDistanceRs = 215;            // Earth distance in solar radii (1 AU)
        $measurementPointRs = 21.5;        // CME measurement point in solar radii
        
        // Calculate distance from measurement point to Earth
        $distanceRs = $earthDistanceRs - $measurementPointRs;  // 193.5 Rs
        $distanceKm = $distanceRs * $solarRadiusKm;            // ~134.7 million km
        
        // Calculate travel time (distance / speed)
        $travelTimeSeconds = $distanceKm / $speedKmPerSec;
        
        // Calculate arrival time
        $arrivalTime = $time21_5_timestamp + $travelTimeSeconds;
        
        // Log the calculation for debugging
        $this->logger->debug(sprintf(
            "CME ballistic calculation: speed=%.0f km/s, distance=%.1f million km, travel time=%.1f hours, arrival=%s",
            $speedKmPerSec,
            $distanceKm / 1000000,
            $travelTimeSeconds / 3600,
            date('Y-m-d H:i:s', (int) $arrivalTime)
        ));
        
        return (int) $arrivalTime;
    }

    /**
     * Extract coordinates from CME raw record
     * 
     * Priority order:
     * 1. sourceLocation string (using startTime for coordinate_time)
     * 2. Fallback to most accurate cmeAnalyses latitude/longitude (using time21_5 for coordinate_time)
     * 
     * @param array $rawRecord The raw CME data
     * @return array Array with latitude, longitude, and coordinate_time
     * @throws CoordinateResolutionException If no valid coordinates found
     */
    private function extractCoordinates(array $rawRecord): array
    {
        // First try to parse sourceLocation
        if (isset($rawRecord['sourceLocation']) && !empty(trim($rawRecord['sourceLocation']))) {
            $location = trim($rawRecord['sourceLocation']);
            
            // Use LocationParser to parse Stonyhurst coordinates (e.g., "N15W30")
            if (strlen($location) >= 6) {
                $coordinates = LocationParser::ParseText($location);
                $latitude = (float) $coordinates[0];
                $longitude = (float) $coordinates[1];
                
                // Validate the parsed coordinates
                if (LocationParser::IsValidLatitudeLongitude($latitude, $longitude)) {
                    return [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'coordinate_time' => strtotime($rawRecord['startTime']), // Use start time for sourceLocation
                        'source' => 'sourceLocation'
                    ];
                } else {
                    $this->logger->debug("CME sourceLocation coordinates invalid: lat={$latitude}, lon={$longitude} from location='{$location}'");
                }
            } else {
                $this->logger->debug("CME sourceLocation too short (< 6 chars): '{$location}'");
            }
        }
        
        // Fallback to coordinates from best cmeAnalyses
        if (isset($rawRecord['cmeAnalyses']) && is_array($rawRecord['cmeAnalyses'])) {
            $analysis = $this->selectBestAnalysis($rawRecord['cmeAnalyses']);
            
            if ($analysis && isset($analysis['latitude']) && isset($analysis['longitude'])
                && !is_null($analysis['latitude']) && !is_null($analysis['longitude'])) {

                // Validate and use time21_5 for coordinate_time when using cmeAnalyses
                $coordinateTime = null;

                if (isset($analysis['time21_5'])) {
                    $time21_5_parsed = strtotime($analysis['time21_5']);
                    if ($time21_5_parsed === false || $time21_5_parsed <= 0) {
                        throw new CoordinateResolutionException(
                            "Invalid time21_5 timestamp: '{$analysis['time21_5']}' is not a valid time string"
                        );
                    }
                    $coordinateTime = $time21_5_parsed;
                } else {
                    // Fallback to startTime if time21_5 not available
                    $coordinateTime = strtotime($rawRecord['startTime']);
                }

                return [
                    'latitude' => (float) $analysis['latitude'],
                    'longitude' => (float) $analysis['longitude'],
                    'coordinate_time' => $coordinateTime,
                    'source' => 'cmeAnalyses'
                ];
            }
        }
        
        throw new CoordinateResolutionException("No valid coordinates found in CME record - missing sourceLocation and cmeAnalyses");
    }

    /**
     * Determines if this processor can handle the given source data
     *
     * Validates that the source is a DONKI CME data stream and contains
     * the required activityID field that uniquely identifies CME events
     * in the DONKI database.
     *
     * @param SourceInterface $source    The data source instance
     * @param array           $rawRecord The raw event data record from the source
     *
     * @return bool True if this processor can handle the data, false otherwise
     */
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        return $source->getName() === 'DONKI_CME' && isset($rawRecord['activityID']);
    }

    /**
     * Processes and transforms raw DONKI CME data into standardized Event model
     *
     * This method performs the complete transformation pipeline for DONKI CME data:
     * 1. Builds the label and short label from the most accurate analysis
     * 2. Extracts and normalizes temporal data (start, peak, end times)
     * 3. Transforms coordinate data to Helioviewer coordinate system
     * 4. Generates unique identifiers and response hashes for deduplication
     * 5. Maps to legacy event type system for backward compatibility
     * 6. Returns an unpersisted Event model (database operations handled by Collector)
     *
     * Coordinate Handling:
     * - CME events typically don't have specific coordinate locations like flares
     * - Coordinates come from the record's source location (extractCoordinates)
     * - Peak time is time21_5 of the best analysis, or the start time without one
     *
     * @param array           $rawRecord The raw CME event data from DONKI
     * @param SourceInterface $source    The source instance (should be DONKI_CME)
     *
     * @return Event The processed (unpersisted) Event model instance
     *
     * @throws \Exception If startTime is not a valid date
     */
    public function process(array $rawRecord, SourceInterface $source): Event
    {
        // Extract coordinates using our own logic
        $coordinates = $this->extractCoordinates($rawRecord);
        
        $startTime = (new \DateTimeImmutable($rawRecord['startTime']))->getTimestamp();

        // Read and keep the model-run images now, so the first request is not the one paying for it
        $this->donkiGifCollector?->warm($rawRecord);

        // Default values
        $endTime = $startTime + 86400; // Default end time is one day after start
        $peakTime = $startTime; // Default peak time is start time
        
        // Calculate peak and end times from cmeAnalyses if available
        if (isset($rawRecord['cmeAnalyses']) && is_array($rawRecord['cmeAnalyses'])) {
            $analysis = $this->selectBestAnalysis($rawRecord['cmeAnalyses']);

            if ($analysis && isset($analysis['time21_5'])) {
                $time21_5 = strtotime($analysis['time21_5']);

                // Only use time21_5 if it's valid and not earlier than start time
                if ($time21_5 !== false && $time21_5 > 0 && $time21_5 >= $startTime) {
                    $peakTime = $time21_5; // Peak time is when CME reaches 21.5 Rs
                    


                    // Calculate end time using the valid peak time
                    if (isset($analysis['speed'])) {
                        $speed = (float) $analysis['speed'];
                        $endTime = $this->calculateBallisticArrivalTime($peakTime, $speed);
                    } else {
                        $endTime = $peakTime + (5 * 24 * 60 * 60); // Add 5 days
                    }

                } else {
                    $this->logger->warning("CME: time21_5 not valid for event times calculation - time21_5: '{$analysis['time21_5']}' ({$time21_5}), start: '{$rawRecord['startTime']}' ({$startTime})");
                }
            }
        }

        // Build standardized event data array for the Event model
        $eventData = [
            'source_id' => JsonSource::CCMC,
            'path' => '',
            'start' => $startTime,
            'peak' => $peakTime, // Peak time is time21_5 when CME reaches 21.5 Rs (or start time as fallback)
            'end' => $endTime,
            'coordinate_time' => $coordinates['coordinate_time'],
            'hv_hpc_x' => $coordinates['longitude'], // Map longitude to HPC X coordinate
            'hv_hpc_y' => $coordinates['latitude'],  // Map latitude to HPC Y coordinate
            'coordinate_system' => 'stonyhurst',
            'label' => $this->label($rawRecord, $startTime),
            'short_label' => $this->shortLabel($rawRecord, $startTime),
            'legacy_version' => $rawRecord['catalog'] ?? null,
            'legacy_type' => 'CE',
            'legacy_pin' => 'CE', // CE for Coronal Ejection
        ];
        
        // Extract region information for later processing
        // NOAA region numbers cycle: after July 2002, 4-digit IDs need +10000
        $regionInfo = null;
        if (isset($rawRecord['activeRegionNum']) && !empty($rawRecord['activeRegionNum'])) {
            $regionId = (int) $rawRecord['activeRegionNum'];
            // Convert 4-digit post-July-2002 IDs to 5-digit format
            if ($regionId > 0 && $regionId < 9000) {
                $regionId += 10000;
            }
            $regionInfo = [
                'organization' => 'NOAA',
                'external_id' => (string) $regionId
            ];
        }

        // Log processed event details
        $startFormatted = date('Y-m-d H:i:s', $eventData['start']);
        $endFormatted = date('Y-m-d H:i:s', $eventData['end']);
        $coordinateTimeFormatted = date('Y-m-d H:i:s', $eventData['coordinate_time']);
        $this->logger->debug("DonkiCmeProcessor | {$eventData['label']} | {$startFormatted} to {$endFormatted} | coord_time: {$coordinateTimeFormatted} | coords: ({$eventData['hv_hpc_x']}, {$eventData['hv_hpc_y']})");

        // Create unpersisted Event model instance
        // Database operations (create/update) will be handled by Collector
        $event = new Event();
        $event->fill($eventData);

        // Add region info to event for Collector to process
        if ($regionInfo) {
            $event->region_info = $regionInfo;
        }

        return $event;
    }
}
