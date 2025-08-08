<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Processors\CCMC\FlareScoreboard;

use Helioviewer\EventsApi\Processors\AbstractProcessor;
use Helioviewer\EventsApi\Models\Event;
use Helioviewer\EventsApi\Sources\JsonSource;
use Helioviewer\EventsApi\Sources\SourceInterface;
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
 * @package    Helioviewer\EventsApi\Processors\CCMC\FlareScoreboard
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 * @see        https://ccmc.gsfc.nasa.gov/challenges/flare-scoreboard/
 * @see        ProcessorInterface
 */
class Processor extends AbstractProcessor
{
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
     * Get timeline information for FlareScoreboard predictions.
     *
     * @param array $rawRecord The raw event data
     * @return array Array with keys: start, peak, end (as timestamps)
     */
    protected function getTimeline(array $rawRecord): array
    {
        // For predictions, use start_window for start time
        $startTime = strtotime($rawRecord['start_window']);
        // End window is the prediction target time
        $endTime = strtotime($rawRecord['end_window'] ?? $rawRecord['start_window']);
        
        return [
            'start' => $startTime,
            'peak' => $endTime,  // Peak is set to end_window for predictions
            'end' => $endTime
        ];
    }

    /**
     * Calculate remaining event fields specific to FlareScoreboard processor.
     *
     * @param array $rawRecord The raw event data
     * @param array $eventData The already calculated base event data
     * @param SourceInterface $source The source object
     * @return array Additional event data fields
     */
    protected function calculateRest(array $rawRecord, array $eventData, SourceInterface $source): array
    {
        // Extract model name from source for proper labeling
        $modelName = method_exists($source, 'getModelName') ? $source->getModelName() : 'Unknown Model';
        
        // Create labels using helper function
        // Coordinate source is provided in eventData
        $labels = $this->createLabels($rawRecord, $modelName, $eventData['region'] ?? null, $eventData['coordinate_source'] ?? null);

        // Build additional event data specific to FlareScoreboard
        return [
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
            'legacy_links' => []
        ];
    }

}
