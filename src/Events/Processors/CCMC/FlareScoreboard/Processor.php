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
use HelioviewerEventInterface\Translator\FlarePrediction;
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
    public function __construct(
        ?LoggerInterface $logger = null,
        ?SentryClientInterface $sentry = null
    ) {
        parent::__construct($logger, $sentry);
    }
    /**
     * Create labels for FlareScoreboard prediction to match event interface format exactly
     * 
     * @param array $rawRecord The raw record from FlareScoreboard
     * @param string $modelName The model name (dataset)
     * @param int|string|null $regionId The region ID if available (can be NOAA number or HARP_xxx format)
     * @param string|null $source The coordinate source (NOAA or Catania)
     * @return array Array with 'label' and 'short_label'
     */
    protected function createLabels(array $rawRecord, string $modelName, int|string|null $regionId, ?string $source): array
    {
        // Create short label with flare class predictions (each on new line)
        $shortLabel = "";
        $flareClasses = ["C", "CPlus", "M", "MPlus", "X"];
        
        foreach ($flareClasses as $class) {
            // Use the class name directly as the key in rawRecord
            if (isset($rawRecord[$class]) && !is_null($rawRecord[$class])) {
                $probability = round($rawRecord[$class] * 100, 2) . "%";
                // Convert class name for display (CPlus -> C+, MPlus -> M+)
                $displayClass = str_replace("PLUS", "+", strtoupper($class));
                if ($shortLabel !== "") {
                    $shortLabel .= "\n";
                }
                $shortLabel .= $displayClass . ": " . $probability;
            }
        }
        
        // Handle case where no predictions are available
        if ($shortLabel === "") {
            $shortLabel = "No probabilities given";
        }
        
        // Main label format: "{dataset} \n{short_label}"
        $label = $modelName . " \n" . $shortLabel;
        
        return [
            'label' => $label,
            'short_label' => "\n" . $shortLabel
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
     * Read coordinates from specific field prefix in raw record.
     *
     * @param array $rawRecord The raw event data
     * @param string $field The field prefix (e.g., 'NOAA', 'Catania', 'Model')
     * @return array|null Coordinate data with latitude, longitude, region, time, and source
     */
    protected function readCoordinatesFromField(array $rawRecord, string $field): ?array {

        $regionKey = $field . 'RegionId';
        $latKey = $field . 'Latitude';
        $lonKey = $field . 'Longitude';
        $timeKey = $field . 'LocationTime';
        
        $this->logger->debug("Checking {$field} coordinates - Region: " . ($rawRecord[$regionKey] ?? 'null') . 
                           ", Lat: " . ($rawRecord[$latKey] ?? 'null') . ", Lon: " . ($rawRecord[$lonKey] ?? 'null'));
        
        // Check if fields have the required data (isset handles 0 values correctly)
        if (isset($rawRecord[$regionKey]) && 
            $rawRecord[$regionKey] !== '' &&
            isset($rawRecord[$latKey]) && 
            isset($rawRecord[$lonKey]) &&
            $rawRecord[$latKey] !== '' &&
            $rawRecord[$lonKey] !== '') {
            
            $lat = (float) $rawRecord[$latKey];
            $lon = (float) $rawRecord[$lonKey];
            
            if (LocationParser::IsValidLatitudeLongitude($lat, $lon)) {
                $this->logger->debug("Valid {$field} coordinates found: {$lat}, {$lon}");
                return [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'coordinate_time' => $rawRecord[$timeKey] ?? null,
                    'source' => $field . ' ' . $rawRecord[$regionKey] . '|RawRecord'
                ];
            } else {
                $this->logger->warning("Invalid {$field} coordinates: lat={$lat}, lon={$lon} (outside valid range)");
            }
        } else {
            $this->logger->debug("Missing or empty {$field} coordinate data");
        }

        return null;
    }

    /**
     * Collect all region information from raw record
     * 
     * @param array $rawRecord The raw event data
     * @return array Array of region info arrays (can be empty)
     */
    protected function collectRegions(array $rawRecord): array
    {
        $possibleFields = ['NOAA', 'Catania', 'Model'];
        $regions = [];

        // Collect region IDs from all possible field prefixes
        foreach ($possibleFields as $field) {
            $regionKey = $field . 'RegionId';

            // Use isset to properly handle 0 values
            if (isset($rawRecord[$regionKey]) && $rawRecord[$regionKey] !== '') {
                // For FlareScoreboard, use appropriate organization
                $organization = match($field) {
                    'NOAA' => 'NOAA',
                    'Catania' => 'CATANIA',
                    'Model' => 'MODEL',
                    default => 'UNKNOWN'
                };

                $externalId = $rawRecord[$regionKey];

                // NOAA region numbers cycle: after July 2002, 4-digit IDs need +10000
                if ($organization === 'NOAA' && is_numeric($externalId)) {
                    $regionId = (int) $externalId;
                    if ($regionId > 0 && $regionId < 9000) {
                        $externalId = (string) ($regionId + 10000);
                    }
                }

                $regions[] = [
                    'organization' => $organization,
                    'external_id' => (string) $externalId
                ];
            }
        }

        return $regions;
    }

    /**
     * Read coordinates from raw record using coordinate resolvers.
     *
     * @param array $rawRecord The raw event data
     * @return array|null Array with coordinate data or null if no resolver succeeds
     * @throws CoordinateResolutionException If no resolver can resolve coordinates
     */
    protected function readCoordinates(array $rawRecord): ?array
    {
        $possibleFields = ['NOAA', 'Catania', 'Model'];
        
        $this->logger->debug("Attempting coordinate resolution from fields: " . implode(', ', $possibleFields));
        
        // Try to read coordinates from each possible field prefix
        foreach ($possibleFields as $field) {
            $coordinates = $this->readCoordinatesFromField($rawRecord, $field);
            if ($coordinates !== null) {
                $this->logger->info("Successfully resolved coordinates from {$field} field: {$coordinates['latitude']}, {$coordinates['longitude']}");
                return $coordinates;
            }
        }
        
        // No coordinates found in any field
        $this->logger->error("Failed to resolve coordinates from any field: " . implode(', ', $possibleFields));
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
                "Checked fields: NOAALatitude/Longitude, CataniaLatitude/Longitude, ModelLatitude/Longitude"
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

        // Collect all region information
        $regionsInfo = $this->collectRegions($rawRecord);

        // Step 4: Let subclass calculate the rest of the fields
        // Extract model name from source for proper labeling
        $modelName = method_exists($source, 'getModelName') ? $source->getModelName() : 'Unknown Model';
        
        // Create labels using helper function
        $labels = $this->createLabels($rawRecord, $modelName, null, $coordinates['source'] ?? null);

        // Build additional event data specific to FlareScoreboard
        $additionalData = [
            'source_id' => JsonSource::CCMC,
            'path' => "",
            'label' => $labels['label'],
            'short_label' => $labels['short_label'],
            'legacy_version' => null,
            'legacy_type' => 'FP',     // Flare Prediction type
            'legacy_pin' => 'FP',      // Flare Prediction pin type
            'legacy_views' => [
                [
                    'name' => 'Flare Prediction',
                    'content' => $rawRecord
                ]
            ],
            'legacy_link' => null
        ];
        
        // Step 5: Merge base data with additional data
        $eventData = array_merge($baseEventData, $additionalData);

        // Step 6: Create and fill Event object
        $event = new Event();
        $event->fill($eventData);
        
        // Step 7: Handle legacy views and link separately
        if (isset($additionalData['legacy_views'])) {
            $event->legacy_views = $additionalData['legacy_views'];
        }
        if (isset($additionalData['legacy_link'])) {
            $event->legacy_link = $additionalData['legacy_link'];
        }
        
        // Add regions info to event for Collector to process
        if (!empty($regionsInfo)) {
            $event->regions_info = $regionsInfo;
        }
        
        return $event;
    }

}
