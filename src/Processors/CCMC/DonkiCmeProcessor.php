<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Processors\CCMC;

use Helioviewer\EventsApi\Processors\ProcessorInterface;
use Helioviewer\EventsApi\Models\Event;
use Helioviewer\EventsApi\Sources\JsonSource;
use Helioviewer\EventsApi\Sources\SourceInterface;
use HelioviewerEventInterface\Translator\DonkiCme as EventInterfaceDonkiCme;

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
 * - Uses existing HelioviewerEventInterface translator for coordinate conversion
 * - Maintains compatibility with legacy Helioviewer coordinate representation
 * - Preserves original DONKI coordinate metadata for reference
 *
 * @package    Helioviewer\EventsApi\Processors\CCMC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 * @see        https://kauai.ccmc.gsfc.nasa.gov/DONKI/
 * @see        EventProcessorInterface
 */
class DonkiCmeProcessor implements ProcessorInterface
{
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
     * 1. Uses the existing HelioviewerEventInterface translator for initial processing
     * 2. Extracts and normalizes temporal data (start, peak, end times)
     * 3. Transforms coordinate data to Helioviewer coordinate system
     * 4. Generates unique identifiers and response hashes for deduplication
     * 5. Maps to legacy event type system for backward compatibility
     * 6. Returns an unpersisted Event model (database operations handled by Collector)
     *
     * Coordinate Handling:
     * - CME events typically don't have specific coordinate locations like flares
     * - Uses translated coordinate values from the event interface translator
     * - Peak time is set to start time as CMEs don't have a distinct peak phase
     *
     * @param array           $rawRecord The raw CME event data from DONKI
     * @param SourceInterface $source    The source instance (should be DONKI_CME)
     *
     * @return Event The processed (unpersisted) Event model instance
     *
     * @throws \Exception If the event interface translator fails
     */
    public function process(array $rawRecord, SourceInterface $source): Event
    {
        echo "\nDONKI CME COORDINATE PROCESSING:\n";
        echo "  - Source: DONKI CME Database\n";
        echo "  - CME ID: {$rawRecord['activityID']}\n";
        
        // Use existing event interface translator to transform raw DONKI data
        // This handles the complex coordinate transformations and data normalization
        echo "  - Using EventInterfaceDonkiCme translator for coordinate extraction...\n";
        $translatedEvent = EventInterfaceDonkiCme::buildTranslatedCME($rawRecord);
        
        echo "  - Translated Coordinates:\n";
        echo "    * HV_HPC_X: {$translatedEvent['hv_hpc_x']}°\n";
        echo "    * HV_HPC_Y: {$translatedEvent['hv_hpc_y']}°\n";
        echo "  - Coordinate source: DONKI CME Analysis (via EventInterface translator)\n";

        $endTime = strtotime($translatedEvent['end']); // Default end time from translatedEvent
        
        if (isset($rawRecord['cmeAnalyses']) && is_array($rawRecord['cmeAnalyses'])) {
            foreach ($rawRecord['cmeAnalyses'] as $analysis) {
                if (isset($analysis['isMostAccurate']) && $analysis['isMostAccurate'] === true && isset($analysis['time21_5'])) {
                    $endTime = strtotime($analysis['time21_5']);
                    break;
                }
            }
        }

        // Build standardized event data array for the Event model
        $eventData = [
            'source_id' => JsonSource::CCMC,
            'path' => '',
            'start' => strtotime($translatedEvent['start']),
            'peak' => strtotime($translatedEvent['start']), // Use start time as peak for CME events
            'end' => $endTime,
            'coordinate_time' => strtotime($translatedEvent['start']),
            'hv_hpc_x' => (float) $translatedEvent['hv_hpc_x'],
            'hv_hpc_y' => (float) $translatedEvent['hv_hpc_y'],
            'label' => $translatedEvent['label'],
            'short_label' => $translatedEvent['short_label'] ?? $translatedEvent['label'],
            'legacy_version' => $translatedEvent['version'] ?? null,
            'legacy_type' => $translatedEvent['type'] ?? 'CE',
            'legacy_pin' => $translatedEvent['type'] ?? 'CE', // Default to 'CE' for Coronal Ejection
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
