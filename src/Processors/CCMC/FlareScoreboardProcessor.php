<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Processors\CCMC;

use Helioviewer\EventsApi\Processors\EventProcessorInterface;
use Helioviewer\EventsApi\Models\Event;
use Helioviewer\EventsApi\Sources\AbstractSource;
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
 * @package    Helioviewer\EventsApi\Processors\CCMC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 * @see        https://ccmc.gsfc.nasa.gov/challenges/flare-scoreboard/
 * @see        EventProcessorInterface
 */
class FlareScoreboardProcessor implements EventProcessorInterface
{
    /**
     * Determines if this processor can handle the given source data
     *
     * Validates that the source is FlareScoreboard prediction data by checking
     * for the characteristic 'FLARE_SCOREBOARD' identifier in the source name
     * and the presence of the required 'start_window' field that defines the
     * prediction time interval.
     *
     * @param string $sourceName The name/identifier of the data source
     * @param array  $rawRecord  The raw event data record from the source
     *
     * @return bool True if this processor can handle the data, false otherwise
     */
    public function canProcess(string $sourceName, array $rawRecord): bool
    {
        return str_contains($sourceName, 'FLARE_SCOREBOARD') &&
               isset($rawRecord['start_window']);
    }

    /**
     * Processes and transforms raw FlareScoreboard prediction data into standardized Event model
     *
     * This method performs the complete transformation pipeline for FlareScoreboard data:
     * 1. Extracts model identification from processing context
     * 2. Attempts coordinate extraction from multiple possible field formats
     * 3. Validates coordinate pairs for physical validity
     * 4. Processes prediction time windows (start/end intervals)
     * 5. Generates unique identifiers based on source and content hash
     * 6. Maps to legacy event type system for backward compatibility
     * 7. Returns an unpersisted Event model (database operations handled by EventCollector)
     *
     * Coordinate Processing Algorithm:
     * - Iterates through multiple coordinate field naming conventions
     * - Prioritizes NOAA and Catania observatory coordinate formats
     * - Validates each coordinate pair using LocationParser utility
     * - Stops at first valid coordinate pair found
     * - Falls back to (0,0) if no valid coordinates are found
     *
     * Supported Coordinate Fields:
     * - NOAALatitude/NOAALongitude (NOAA Space Weather Prediction Center)
     * - CataniaLatitude/CataniaLongitude (Catania Solar Observatory)
     * - latitude/longitude (generic HAPI format)
     * - lat/lon (abbreviated generic format)
     *
     * Time Window Handling:
     * - Uses start_window as the primary prediction start time
     * - Uses end_window when available, otherwise defaults to start_window
     * - Peak time is set to end_window to represent the prediction target time
     *
     * @param array  $rawRecord  The raw prediction data from FlareScoreboard
     * @param string $sourceName The source identifier (contains 'FLARE_SCOREBOARD')
     * @param array  $context    Processing context including 'model_name' for labeling
     *
     * @return Event The processed (unpersisted) Event model instance
     */
    public function process(array $rawRecord, string $sourceName, array $context = []): Event
    {
        // Extract model name from context for proper labeling and provenance
        $modelName = $context['model_name'] ?? 'Unknown Model';

        // Initialize coordinate variables with default values
        $latitude = 0.0;
        $longitude = 0.0;

        // Define possible coordinate field names from different data sources
        // HAPI format supports various naming conventions from different observatories
        $latFields = ['NOAALatitude', 'CataniaLatitude', 'latitude', 'lat'];
        $lonFields = ['NOAALongitude', 'CataniaLongitude', 'longitude', 'lon'];

        // Attempt to extract valid coordinates using nested loop approach
        // This ensures we find the first valid latitude/longitude pair
        foreach ($latFields as $field) {
            if (isset($rawRecord[$field]) && $rawRecord[$field] !== null) {
                $lat = (float) $rawRecord[$field];
                foreach ($lonFields as $lonField) {
                    if (isset($rawRecord[$lonField]) && $rawRecord[$lonField] !== null) {
                        $lon = (float) $rawRecord[$lonField];
                        // Validate coordinates are within physical bounds for solar coordinates
                        if (LocationParser::IsValidLatitudeLongitude($lat, $lon)) {
                            $latitude = $lat;
                            $longitude = $lon;
                            break 2; // Exit both loops once valid coordinates are found
                        }
                    }
                }
            }
        }

        // Generate unique identifier for prediction events
        // Combines source name and record content to ensure uniqueness
        $id = 'fs_' . md5($sourceName . json_encode($rawRecord));

        // Build standardized event data array for the Event model
        $eventData = [
            'remote_id' => $id,
            'response_hash' => md5(json_encode($rawRecord)),
            'source_id' => AbstractSource::CCMC,
            'path' => 'CCMC>>Solar Flare Predictions>>' . $modelName,
            'start' => strtotime($rawRecord['start_window']),
            // Use end_window as peak if available, otherwise use start_window
            'peak' => isset($rawRecord['end_window'])
                ? strtotime($rawRecord['end_window'])
                : strtotime($rawRecord['start_window']),
            'end' => strtotime($rawRecord['end_window'] ?? $rawRecord['start_window']),
            'hv_hpc_x' => $latitude,   // Map latitude to HPC X coordinate
            'hv_hpc_y' => $longitude,  // Map longitude to HPC Y coordinate
            'label' => 'Flare Prediction - ' . $modelName,
            'translator' => 'FlarePrediction',
            'legacy_version' => null,
            'legacy_type' => 'FP',     // Flare Prediction type
            'legacy_pin' => 'FP',      // Flare Prediction pin type
        ];

        // Create unpersisted Event model instance
        // Database operations (create/update) will be handled by EventCollector
        $event = new Event();
        $event->fill($eventData);
        
        return $event;
    }
}