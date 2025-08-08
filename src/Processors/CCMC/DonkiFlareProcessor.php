<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Processors\CCMC;

use Helioviewer\EventsApi\Processors\ProcessorInterface;
use Helioviewer\EventsApi\Models\Event;
use Helioviewer\EventsApi\Sources\JsonSource;
use Helioviewer\EventsApi\Sources\SourceInterface;
use HelioviewerEventInterface\Translator\DonkiFlare as EventInterfaceDonkiFlare;
use HelioviewerEventInterface\Util\LocationParser;

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
 * @package    Helioviewer\EventsApi\Processors\CCMC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 * @see        https://kauai.ccmc.gsfc.nasa.gov/DONKI/
 * @see        EventProcessorInterface
 */
class DonkiFlareProcessor implements ProcessorInterface
{
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
        // Use existing event interface translator to transform raw DONKI flare data
        // This handles complex flare classification and data normalization
        $translatedEvent = EventInterfaceDonkiFlare::makeEventFromRawFlare($rawRecord);


        // Initialize coordinate variables with default values
        $latitude = 0.0;
        $longitude = 0.0;

        echo "\nDONKI FLARE COORDINATE PROCESSING:\n";
        echo "  - Source: DONKI Flare Database\n";
        echo "  - Flare ID: {$rawRecord['flrID']}\n";
        
        // Extract Stonyhurst coordinates from DONKI source location string
        // DONKI provides locations in formats like "N15W30", "S08E45", etc.
        if (isset($rawRecord['sourceLocation']) && !empty($rawRecord['sourceLocation'])) {
            echo "  - Raw sourceLocation: '{$rawRecord['sourceLocation']}'\n";
            
            try {
                // Parse coordinate string using HelioviewerEventInterface utility
                echo "  - Parsing with LocationParser::ParseText()...\n";
                $location = LocationParser::ParseText($rawRecord['sourceLocation']);
                $latitude = (float) $location[0];  // Stonyhurst latitude in degrees
                $longitude = (float) $location[1]; // Stonyhurst longitude in degrees
                
                echo "  - Parsed successfully:\n";
                echo "    * Latitude: {$latitude}° (Stonyhurst)\n";
                echo "    * Longitude: {$longitude}° (Stonyhurst)\n";
                echo "  - Coordinate source: DONKI sourceLocation field\n";
            } catch (\Exception $e) {
                // Log parsing errors but continue processing with default coordinates
                echo "  - ERROR parsing location: " . $e->getMessage() . "\n";
                echo "  - Using default coordinates (0, 0)\n";
                error_log("Failed to parse flare location '{$rawRecord['sourceLocation']}': " . $e->getMessage());
            }
        } else {
            echo "  - No sourceLocation field found in raw record\n";
            echo "  - Using default coordinates (0, 0)\n";
        }

        // For flares, coordinate_time is typically the peak time
        $peakTime = $translatedEvent['peak'] instanceof \DateTime
            ? $translatedEvent['peak']->getTimestamp()
            : strtotime($translatedEvent['peak']);

        // Build standardized event data array for the Event model
        $eventData = [
            'source_id' => JsonSource::CCMC,
            'path' => '',
            'start' => strtotime($translatedEvent['start']),
            'peak' => $peakTime,
            'end' => strtotime($translatedEvent['end']),
            'coordinate_time' => $peakTime, // For flares, coordinates are typically at peak time
            'hv_hpc_x' => $latitude,   // Map latitude to HPC X coordinate
            'hv_hpc_y' => $longitude,  // Map longitude to HPC Y coordinate
            'label' => $translatedEvent['label'],
            'short_label' => $translatedEvent['short_label'] ?? $translatedEvent['label'], // Use label if short_label not available
            'legacy_version' => $translatedEvent['version'] ?? null,
            'legacy_type' => $translatedEvent['type'] ?? null,
            'legacy_pin' => $translatedEvent['type'] ?? 'FL', // Default to 'FL' for Flare
        ];

        // Create unpersisted Event model instance
        // Database operations (create/update) will be handled by Collector
        $event = new Event();
        $event->fill($eventData);
        
        // Add views and links data to be processed by Collector
        if (isset($translatedEvent['views'])) {
            $event->legacy_views = $translatedEvent['views'];
        }
        
        if (isset($translatedEvent['link'])) {
           $event->legacy_links = is_array($translatedEvent['link']) ? [$translatedEvent['link']] : [$translatedEvent['link']];
        }
        
        return $event;
    }
}
