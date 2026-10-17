<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard;

use Psr\Log\LoggerInterface;
use Helioviewer\EventsApi\Events\Processors\BaseProcessor;
use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use Helioviewer\EventsApi\Exception\CoordinateResolutionException;
use Helioviewer\EventsApi\Regions\ActiveRegionPositions;
use HelioviewerEventInterface\Util\LocationParser;

/**
 * FlareScoreboard Prediction Data Processor
 *
 * This processor handles the transformation and normalization of solar flare
 * prediction data from various FlareScoreboard models provided by the Community
 * Coordinated Modeling Center (CCMC). FlareScoreboard is a framework for
 * evaluating and comparing different solar flare prediction models using
 * standardized metrics and time windows.
 *
 * The processor handles:
 * - Solar flare prediction model data validation and processing
 * - Coordinate extraction from multiple HAPI data format field variations
 * - Time window processing for prediction intervals (start/end windows)
 * - Model-specific labeling and categorization
 * - Data deduplication based on generated unique identifiers
 * - Legacy compatibility mapping for existing Helioviewer event types
 *
 * Data Sources Supported:
 * - CCMC FlareScoreboard prediction models (multiple model types)
 * - HAPI (Heliophysics Application Programmer's Interface) formatted data
 * - Real-time and retrospective flare prediction datasets
 * - Active region coordinate data from various observatories (NOAA, Catania)
 *
 * Coordinate System Processing:
 * - Supports multiple coordinate field naming conventions
 * - Handles NOAA and Catania observatory coordinate formats
 * - Validates coordinate pairs for physical validity
 * - Maps Stonyhurst heliographic coordinates to Helioviewer coordinate system
 * - Falls back gracefully when coordinate data is unavailable
 *
 * Model Integration:
 * - Processes predictions from multiple forecasting models simultaneously
 * - Maintains model provenance through context-aware labeling
 * - Supports various prediction time windows and confidence intervals
 *
 * @package    Helioviewer\EventsApi\Collector\Processors\CCMC\FlareScoreboard
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 * @see        https://ccmc.gsfc.nasa.gov/challenges/flare-scoreboard/
 * @see        ProcessorInterface
 */
class Processor extends BaseProcessor
{
    /**
     * @param LoggerInterface|null       $logger    Logger
     * @param SentryClientInterface|null $sentry    Sentry client
     * @param ActiveRegionPositions|null $positions Where NOAA regions were (HEK, else JSOC)
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?SentryClientInterface $sentry = null,
        protected ?ActiveRegionPositions $positions = null
    ) {
        parent::__construct($logger, $sentry);
    }
    /**
     * Labels for a prediction: "<model> | <region>" over one probability per
     * line, and the one-line "<region> | <probabilities>" for the pin; without
     * a NOAA or HARP region, just "<model>" and "<probabilities>".
     *
     * @param array       $rawRecord The raw record (fills already null)
     * @param string      $modelName The model name, e.g. "ASSA"
     * @param string|null $region    The event's region, e.g. "AR 14548" or "HARP 13398"
     * @return array Array with 'label' and 'short_label'
     */
    protected function createLabels(array $rawRecord, string $modelName, ?string $region): array
    {
        $probabilities = [];
        foreach (["C", "CPlus", "M", "MPlus", "X"] as $class) {
            if (isset($rawRecord[$class])) {
                // CPlus -> C+, MPlus -> M+
                $probabilities[] = str_replace("PLUS", "+", strtoupper($class)) . ": " . round($rawRecord[$class] * 100, 2) . "%";
            }
        }
        if ($probabilities === []) {
            $probabilities = ["No probabilities given"];
        }

        return [
            'label' => $modelName . ($region !== null ? " | {$region}" : '') . "\n" . implode("\n", $probabilities),
            'short_label' => ($region !== null ? "{$region} | " : '') . implode(", ", $probabilities),
        ];
    }

    /**
     * Determines if this processor can handle the given source data
     *
     * Validates that the source is FlareScoreboard prediction data by checking
     * for the characteristic 'FLARE_SCOREBOARD' identifier in the source name
     * and the presence of the required 'start_window' field.
     *
     * @param SourceInterface $source    The data source instance
     * @param array           $rawRecord The raw event data record from the source
     *
     * @return bool True if this processor can handle the data, false otherwise
     */
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        // Must be a FlareScoreboard source with start_window
        return str_contains($source->getName(), 'FLARE_SCOREBOARD') && isset($rawRecord['start_window']);
    }

    /**
     * Read coordinates for a prediction.
     *
     * A row naming a NOAA region takes that region's position from HEK's
     * active-region records (JSOC as the fallback), never from the row: CCMC's
     * models send positions mirrored (SIDC), a day stale or differently encoded
     * (BoM), or NOAA's midnight position stamped hours later (MAG4). When no
     * active region can be found the row keeps CCMC's own position, flagged.
     *
     * @param array $rawRecord The raw event data
     * @return array|null Coordinate data, or null when the row has no position at all
     */
    protected function readCoordinates(array $rawRecord): ?array
    {
        if (isset($rawRecord['NOAARegionId']) && (int) $rawRecord['NOAARegionId'] > 0) {
            $coordinates = $this->readCoordinatesFromNOAARegion((int) $rawRecord['NOAARegionId'], $rawRecord['start_window']);
            if ($coordinates !== null) {
                return $coordinates;
            }
        }

        return $this->readCoordinatesFromRow($rawRecord);
    }

    /**
     * A NOAA region's position at or before $datetime (ActiveRegionPositions),
     * with that region as the event's linked region.
     *
     * @param array $alsoLink More regions to link (e.g. the HARP that named it)
     * @return array|null Coordinate data, or null when nothing knows the region
     */
    protected function readCoordinatesFromNOAARegion(int $noaaId, string $datetime, array $alsoLink = []): ?array
    {
        // 4-digit numbers are the cycled form (4548 = 14548)
        $noaaId = $noaaId < 9000 ? $noaaId + 10000 : $noaaId;

        $position = $this->positions?->forNoaa($noaaId, strtotime($datetime));
        if ($position === null) {
            $this->logger->warning("No position for NOAA region {$noaaId} at or before {$datetime} (HEK or JSOC)");
            return null;
        }

        return $position + ['regions' => array_merge([['organization' => 'NOAA', 'external_id' => (string) $noaaId]], $alsoLink)];
    }

    /**
     * CCMC's own position when no active region can be placed: the row's NOAA
     * fields, else its Model fields. Kept (CCMC's data is never dropped) and
     * flagged in the position source; a NOAA number in the row is still linked.
     *
     * @param array $rawRecord The raw event data
     * @return array|null Coordinate data, or null when the row has no usable position
     */
    protected function readCoordinatesFromRow(array $rawRecord): ?array
    {
        $regions = isset($rawRecord['NOAARegionId']) && (int) $rawRecord['NOAARegionId'] > 0
            ? [['organization' => 'NOAA', 'external_id' => (string) ((int) $rawRecord['NOAARegionId'] < 9000 ? (int) $rawRecord['NOAARegionId'] + 10000 : (int) $rawRecord['NOAARegionId'])]]
            : [];

        // NOAA fields first: when a NOAA region could not be placed, its own row position is the next best
        foreach (['NOAA', 'Model'] as $field) {
            if (!isset($rawRecord[$field . 'Latitude'], $rawRecord[$field . 'Longitude'])) {
                continue;
            }
            $lat = (float) $rawRecord[$field . 'Latitude'];
            $lon = (float) $rawRecord[$field . 'Longitude'];
            if (!LocationParser::IsValidLatitudeLongitude($lat, $lon)) {
                $this->logger->warning("Out-of-range {$field} position in row: {$lat}, {$lon}");
                continue;
            }

            $this->logger->info("Position | CCMC ROW | no active region: keeping CCMC's {$field} position ({$lat}, {$lon})");

            return [
                'latitude' => $lat,
                'longitude' => $lon,
                'coordinate_time' => $rawRecord[$field . 'LocationTime'] ?? $rawRecord['start_window'],
                'source' => 'CCMC (no active region)',
                'regions' => $regions,
            ];
        }

        return null;
    }

    /**
     * Template method implementation of process().
     * 
     * This method defines the processing workflow:
     * 1. Get timeline information (start, peak, end)
     * 2. Get coordinates from resolvers
     * 3. Build base event data
     * 4. Let subclasses calculate remaining fields
     * 5. Return filled Event object
     *
     * @param array $rawRecord Raw event data from data source
     * @param SourceInterface $source The source object providing context and metadata
     * @return Event Processed and validated Event model instance
     */
    public function process(array $rawRecord, SourceInterface $source): Event
    {
        // Step 1: Get timeline information
        // For predictions, use start_window for start time
        $startTime = strtotime($rawRecord['start_window']);
        // End window is the prediction target time
        $endTime = strtotime($rawRecord['end_window'] ?? $rawRecord['start_window']);
        
        $timeline = [
            'start' => $startTime,
            'peak' => $endTime,  // Peak is set to end_window for predictions
            'end' => $endTime
        ];


        // Step 2: Get coordinates
        $coordinates = $this->readCoordinates($rawRecord);

        // Throw exception if no coordinates could be found
        if ($coordinates === null) {
            throw new CoordinateResolutionException(
                "No valid coordinates found in raw record. " .
                "No active region and no in-range NOAA or Model position in the row"
            );
        }
        
        // Step 3: Build base event data with coordinates and timeline
        // Use coordinate_time if available and valid, otherwise fallback to start_window
        $coordinateTime = $coordinates['coordinate_time'];
        if (empty($coordinateTime)) {
            $coordinateTime = $rawRecord['start_window'] ?? null;
            if ($coordinateTime) {
                $this->logger->debug("Using start_window as coordinate_time fallback");
            }
        }

        $baseEventData = [
            'start' => $timeline['start'],
            'peak' => $timeline['peak'],
            'end' => $timeline['end'],
            'coordinate_time' => strtotime($coordinateTime),
            'hv_hpc_x' => $coordinates['longitude'],  // longitude maps to X
            'hv_hpc_y' => $coordinates['latitude'],   // latitude maps to Y
            'coordinate_system' => 'stonyhurst',
        ];

        // The active region (and any HARP) found while placing the row
        $regionsInfo = $coordinates['regions'] ?? [];

        // Step 4: Let subclass calculate the rest of the fields
        // Extract model name from source for proper labeling
        $modelName = method_exists($source, 'getModelName') ? $source->getModelName() : 'Unknown Model';
        
        // Create labels using helper function
        // The event's NOAA region as "AR 14548" (5 digits), or its HARP; none when the row could not be placed on one
        $region = match ($regionsInfo[0]['organization'] ?? null) {
            null => null,
            'NOAA' => "AR {$regionsInfo[0]['external_id']}",
            default => "{$regionsInfo[0]['organization']} {$regionsInfo[0]['external_id']}",
        };
        $labels = $this->createLabels($rawRecord, $modelName, $region);

        // Build additional event data specific to FlareScoreboard
        $additionalData = [
            'source_id' => JsonSource::CCMC,
            'path' => "",
            'label' => $labels['label'],
            'short_label' => $labels['short_label'],
            'legacy_version' => null,
            'legacy_type' => 'FP',     // Flare Prediction type
            'legacy_pin' => 'FP',      // Flare Prediction pin type
        ];
        
        // Step 5: Merge base data with additional data
        $eventData = array_merge($baseEventData, $additionalData);

        // Step 6: Create and fill Event object
        $event = new Event();
        $event->fill($eventData);
        
        // Add regions info to event for Collector to process
        if (!empty($regionsInfo)) {
            $event->regions_info = $regionsInfo;
        }
        // Where the position came from, without its time: counted in the batch summary
        $event->position_source = preg_replace('/ \d{4}-.*$/', '', (string) ($coordinates['source'] ?? ''));
        
        return $event;
    }

}
