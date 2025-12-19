<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\CCMC;

use Helioviewer\EventsApi\Events\Processors\ProcessorInterface;
use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use HelioviewerEventInterface\Translator\DonkiFlare as EventInterfaceDonkiFlare;
use HelioviewerEventInterface\Util\LocationParser;
use Helioviewer\EventsApi\Exception\CoordinateResolutionException;
use Helioviewer\EventsApi\Exception\InvalidEventException;
use Psr\Log\LoggerInterface;

/**
 * DONKI Solar Flare Event Processor
 *
 * This processor handles the transformation and normalization of DONKI solar flare
 * event data from the Community Coordinated Modeling Center (CCMC). It processes
 * raw solar flare data from the DONKI (Database Of Notifications, Knowledge,
 * Information) service and converts it into the standardized Event model format
 * used by the Helioviewer Events API.
 *
 * The processor handles:
 * - Solar flare activity identification and validation
 * - Coordinate transformation from Stonyhurst heliographic to Helioviewer coordinate system
 * - Event timeline processing with distinct start, peak, and end phases
 * - Location parsing from DONKI source location strings
 * - Data deduplication based on remote ID and source
 * - Legacy compatibility mapping for existing Helioviewer event types
 *
 * Data Sources Supported:
 * - DONKI solar flare analysis data via CCMC API
 * - Real-time and historical solar flare event records
 * - Flare classification data (X, M, C-class flares)
 * - Associated active region and coordinate information
 *
 * Coordinate System Transformations:
 * - Parses Stonyhurst heliographic coordinates from DONKI sourceLocation field
 * - Converts coordinate strings (e.g., "N15W30") to decimal degrees
 * - Maps latitude/longitude to Helioviewer HPC coordinate system
 * - Handles coordinate parsing errors gracefully with fallback to (0,0)
 *
 * @package    Helioviewer\EventsApi\Collector\Processors\CCMC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 * @see        https://kauai.ccmc.gsfc.nasa.gov/DONKI/
 * @see        EventProcessorInterface
 */
class DonkiFlareProcessor implements ProcessorInterface
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
    }

    /**
     * Extract coordinates from Flare raw record using sourceLocation
     * 
     * @param array $rawRecord The raw Flare data
     * @return array Array with latitude, longitude, and coordinate_time
     * @throws CoordinateResolutionException If no valid coordinates found
     */
    private function extractCoordinates(array $rawRecord): array
    {
        // Extract from sourceLocation
        if (isset($rawRecord['sourceLocation']) && !empty(trim($rawRecord['sourceLocation']))) {
            $location = trim($rawRecord['sourceLocation']);
            
            // Use LocationParser to parse Stonyhurst coordinates (e.g., "N15W30")
            if (strlen($location) >= 6) {
                $coordinates = LocationParser::ParseText($location);
                $latitude = (float) $coordinates[0];
                $longitude = (float) $coordinates[1];
                
                // Validate the parsed coordinates
                if (LocationParser::IsValidLatitudeLongitude($latitude, $longitude)) {
                    // For flares, coordinate_time is typically the peak time
                    $coordinateTime = isset($rawRecord['peakTime']) 
                        ? strtotime($rawRecord['peakTime'])
                        : strtotime($rawRecord['beginTime']); // Fallback to beginTime
                        
                    return [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'coordinate_time' => $coordinateTime,
                        'source' => 'sourceLocation'
                    ];
                } else {
                    $this->logger->debug("Flare sourceLocation coordinates invalid: lat={$latitude}, lon={$longitude} from location='{$location}'");
                }
            } else {
                $this->logger->debug("Flare sourceLocation too short (< 6 chars): '{$location}'");
            }
        }
        
        throw new CoordinateResolutionException("No valid coordinates found in Flare record - missing or invalid sourceLocation");
    }

    /**
     * Determines if this processor can handle the given source data
     *
     * Validates that the source is a DONKI solar flare data stream and contains
     * the required activityID field that uniquely identifies flare events
     * in the DONKI database.
     *
     * @param string $sourceName The name/identifier of the data source
     * @param array  $rawRecord  The raw event data record from the source
     *
     * @return bool True if this processor can handle the data, false otherwise
     */
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        return $source->getName() === 'DONKI_FLARE' && isset($rawRecord['flrID']);
    }

    /**
     * Processes and transforms raw DONKI solar flare data into standardized Event model
     *
     * This method performs the complete transformation pipeline for DONKI flare data:
     * 1. Uses the existing HelioviewerEventInterface translator for initial processing
     * 2. Extracts and parses coordinate information from sourceLocation field
     * 3. Transforms Stonyhurst coordinates to Helioviewer coordinate system
     * 4. Processes temporal data with proper peak time handling
     * 5. Generates unique identifiers and response hashes for deduplication
     * 6. Maps to legacy event type system for backward compatibility
     * 7. Returns an unpersisted Event model (database operations handled by Collector)
     *
     * Coordinate Processing:
     * - Parses DONKI sourceLocation strings (e.g., "N15W30", "S08E45")
     * - Converts to Stonyhurst heliographic latitude/longitude in decimal degrees
     * - North/South prefixes determine latitude sign (N=positive, S=negative)
     * - East/West prefixes determine longitude sign (E=positive, W=negative)
     * - Falls back to (0,0) coordinates if parsing fails
     *
     * Temporal Data Handling:
     * - Preserves start, peak, and end times from DONKI data
     * - Handles DateTime objects and string timestamps from translator
     * - Peak time represents the maximum intensity phase of the flare
     *
     * @param array  $rawRecord  The raw flare event data from DONKI
     * @param string $sourceName The source identifier (should be 'DONKI_FLARE')
     * @param array  $context    Additional processing context (currently unused)
     *
     * @return Event The processed (unpersisted) Event model instance
     *
     * @throws \Exception If the event interface translator fails
     */
    public function process(array $rawRecord, SourceInterface $source): Event
    {
        // Extract coordinates using our own logic
        $coordinates = $this->extractCoordinates($rawRecord);
        
        // Use existing event interface translator to transform raw DONKI flare data
        // This handles complex flare classification and data normalization
        // Let any exceptions bubble up to the Collector's exception handler
        $translatedEvent = EventInterfaceDonkiFlare::makeEventFromRawFlare($rawRecord);

        // For flares, peak time from translated event
        $peakTime = $translatedEvent['peak'] instanceof \DateTime
            ? $translatedEvent['peak']->getTimestamp()
            : strtotime($translatedEvent['peak']);

        // Build standardized event data array for the Event model
        $startTime = strtotime($translatedEvent['start']);
        $endTime = strtotime($translatedEvent['end']);

        // Validate temporal ordering: start ≤ peak ≤ end
        if ($startTime > $peakTime || $peakTime > $endTime || $startTime > $endTime) {
            throw new InvalidEventException(
                "Invalid temporal ordering for flare event - start: {$translatedEvent['start']} ({$startTime}), " .
                "peak: {$peakTime}, end: {$translatedEvent['end']} ({$endTime})"
            );
        }

        $eventData = [
            'source_id' => JsonSource::CCMC,
            'path' => '',
            'start' => $startTime,
            'peak' => $peakTime,
            'end' => $endTime,
            'coordinate_time' => $coordinates['coordinate_time'],
            'hv_hpc_x' => $coordinates['longitude'],  // Map longitude to HPC X coordinate
            'hv_hpc_y' => $coordinates['latitude'],   // Map latitude to HPC Y coordinate
            'coordinate_system' => 'stonyhurst',
            'label' => $translatedEvent['label'],
            'short_label' => $translatedEvent['short_label'] ?? $translatedEvent['label'], // Use label if short_label not available
            'legacy_version' => $translatedEvent['version'] ?? null,
            'legacy_type' => $translatedEvent['type'] ?? null,
            'legacy_pin' => $translatedEvent['type'] ?? 'FL', // Default to 'FL' for Flare
        ];
        
        // Extract region information for later processing
        $regionInfo = null;
        if (isset($rawRecord['activeRegionNum']) && !empty($rawRecord['activeRegionNum'])) {
            $regionInfo = [
                'organization' => 'NOAA',
                'external_id' => (string) $rawRecord['activeRegionNum']
            ];
        }

        // Log processed event details
        $startFormatted = date('Y-m-d H:i:s', $eventData['start']);
        $peakFormatted = date('Y-m-d H:i:s', $eventData['peak']);
        $endFormatted = date('Y-m-d H:i:s', $eventData['end']);
        $coordinateTimeFormatted = date('Y-m-d H:i:s', $eventData['coordinate_time']);
        $this->logger->debug("DonkiFlareProcessor | {$eventData['label']} | {$startFormatted} to {$endFormatted} (peak: {$peakFormatted}) | coord_time: {$coordinateTimeFormatted} | coords: ({$eventData['hv_hpc_x']}, {$eventData['hv_hpc_y']})");

        // Create unpersisted Event model instance
        // Database operations (create/update) will be handled by Collector
        $event = new Event();
        $event->fill($eventData);
        
        // Add views and links data to be processed by Collector
        if (isset($translatedEvent['views'])) {
            $event->legacy_views = $translatedEvent['views'];
        }
        
        if (isset($translatedEvent['link'])) {
           $event->legacy_link = $translatedEvent['link'];
        }
        
        // Add region info to event for Collector to process
        if ($regionInfo) {
            $event->region_info = $regionInfo;
        }
        
        return $event;
    }
}
