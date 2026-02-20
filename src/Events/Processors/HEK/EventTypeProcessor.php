<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\HEK;

use Helioviewer\EventsApi\Events\Processors\ProcessorInterface;
use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use Helioviewer\EventsApi\Exception\InvalidEventException;
use Psr\Log\LoggerInterface;

/**
 * HEK Event Type Processor
 *
 * Generic processor for HEK events based on event_type.
 * Can be instantiated for different event types (FL, AR, CE, etc.)
 *
 * @package    Helioviewer\EventsApi\Events\Processors\HEK
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class EventTypeProcessor implements ProcessorInterface
{
    protected string $eventType;
    protected LoggerInterface $logger;

    public function __construct(string $eventType, ?LoggerInterface $logger = null)
    {
        $this->eventType = $eventType;
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
    }

    /**
     * Determines if this processor can handle the given source data.
     *
     * @param SourceInterface $source The data source
     * @param array $rawRecord The raw event data record
     *
     * @return bool True if source is HEK and event_type matches
     */
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        return $source->getName() === 'HEK' &&
               isset($rawRecord['event_type']) &&
               $rawRecord['event_type'] === $this->eventType;
    }

    /**
     * Get timeline data from raw record.
     * Can be overridden by subclasses for event-specific behavior.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array ['start' => int, 'peak' => int, 'end' => int, 'coordinate_time' => int]
     * @throws InvalidEventException If end time is before start time
     */
    protected function getTimeLine(array $rawRecord): array
    {
        $start = strtotime($rawRecord['event_starttime']);
        $peakTime = !empty($rawRecord['event_peaktime']) ? strtotime($rawRecord['event_peaktime']) : false;
        $peak = ($peakTime !== false && $peakTime > 0) ? $peakTime : $start;
        $end = strtotime($rawRecord['event_endtime']);

        // Validate time range: end must be >= start
        if ($end < $start) {
            $eventId = $rawRecord['kb_archivid'] ?? 'unknown';
            throw new InvalidEventException(
                "Invalid time range: end ({$rawRecord['event_endtime']}) must be greater than or equal to start ({$rawRecord['event_starttime']}) for event {$eventId}"
            );
        }

        return [
            'start'           => $start,
            'peak'            => $peak,
            'end'             => $end,
            'coordinate_time' => $start,  // Default: use start time
        ];
    }

    /**
     * Build label array for the event.
     * Can be overridden by subclasses for event-specific labels.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array Associative array of label key => value pairs
     */
    protected function buildLabelArray(array $rawRecord): array
    {
        $coord1 = (float)($rawRecord['event_coord1'] ?? $rawRecord['hpc_x'] ?? 0);
        $coord2 = (float)($rawRecord['event_coord2'] ?? $rawRecord['hpc_y'] ?? 0);
        $coords = number_format($coord1, 2) . ',' . number_format($coord2, 2);

        return ['Event Type' => ($rawRecord['concept'] ?? $this->eventType) . ' ' . $coords];
    }

    /**
     * Get label for the event by formatting the label array.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return string Event label
     */
    protected function getLabel(array $rawRecord): string
    {
        $labelArray = $this->buildLabelArray($rawRecord);

        // If subclass returned empty array, use default with coordinates
        if (empty($labelArray)) {
            $coord1 = (float)($rawRecord['event_coord1'] ?? $rawRecord['hpc_x'] ?? 0);
            $coord2 = (float)($rawRecord['event_coord2'] ?? $rawRecord['hpc_y'] ?? 0);
            $coords = number_format($coord1, 2) . ',' . number_format($coord2, 2);
            $labelArray = ['Event Type' => ($rawRecord['concept'] ?? $this->eventType) . ' ' . $coords];
        }

        $out = '';
        foreach ($labelArray as $value) {
            $out .= $value . "\n";
        }
        return rtrim($out, "\n");
    }

    /**
     * Get link for the event.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array Link array with 'url' and 'text' keys, or empty array
     */
    protected function getLink(array $rawRecord): array
    {
        if (!empty($rawRecord['kb_archivid'])) {
            return [
                'url' => 'https://www.lmsal.com/hek/her?cmd=view-voevent&ivorn=' . $rawRecord['kb_archivid'],
                'text' => 'HEK Archive Link'
            ];
        }

        if (!empty($rawRecord['frm_url'])) {
            return [
                'url' => $rawRecord['frm_url'],
                'text' => 'Feature Recognition Method'
            ];
        }

        return [];
    }

    /**
     * Get views for the event.
     *
     * HEK Data Tab Filtering:
     * | Name                         | Filter Logic to generate concept         | Example Fields inside concept                            |
     * |------------------------------|------------------------------------------|----------------------------------------------------------|
     * | $rawRecprd['concept']        | {type}_* OR event* OR concept OR kb_*    | ar_noaanum, event_starttime, concept, kb_archivid        |
     * | Observation                  | obs_*                                    | obs_observatory, obs_instrument, obs_channelid           |
     * | Recognition Method           | frm_*                                    | frm_name, frm_specificid, frm_contact                    |
     * | References                   | refs array                               | ref_name, ref_url                                        |
     * | All                          | Everything EXCEPT hv_* and refs          | All source HEK data                                      |
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array Array of view objects with 'name' and 'content' keys
     */
    protected function getViews(array $rawRecord): array
    {
        $views = [];
        $eventType = $rawRecord['event_type'] ?? $this->eventType;
        $typePrefix = strtolower($eventType) . '_';

        // 1. Event Type view: {type}_* OR event* OR concept OR kb_*
        $eventTypeContent = [];
        foreach ($rawRecord as $key => $value) {
            if (str_starts_with($key, $typePrefix) ||
                str_starts_with($key, 'event') ||
                $key === 'concept' ||
                str_starts_with($key, 'kb_')) {
                $eventTypeContent[$key] = $value;
            }
        }
        if (!empty($eventTypeContent)) {
            $views[] = ['name' => $rawRecord['concept'] ?? $eventType, 'content' => $eventTypeContent];
        }

        // 2. Observation view: obs_*
        $obsContent = [];
        foreach ($rawRecord as $key => $value) {
            if (str_starts_with($key, 'obs_')) {
                $obsContent[$key] = $value;
            }
        }
        if (!empty($obsContent)) {
            $views[] = ['name' => 'Observation', 'content' => $obsContent];
        }

        // 3. Recognition Method view: frm_*
        $frmContent = [];
        foreach ($rawRecord as $key => $value) {
            if (str_starts_with($key, 'frm_')) {
                $frmContent[$key] = $value;
            }
        }
        if (!empty($frmContent)) {
            $views[] = ['name' => 'Recognition Method', 'content' => $frmContent];
        }

        // 4. References view: refs array
        if (!empty($rawRecord['refs']) && is_array($rawRecord['refs'])) {
            $refsContent = [];
            foreach ($rawRecord['refs'] as $ref) {
                if (isset($ref['ref_name']) && isset($ref['ref_url'])) {
                    $refsContent[$ref['ref_name']] = $ref['ref_url'];
                }
            }
            if (!empty($refsContent)) {
                $views[] = ['name' => 'References', 'content' => $refsContent];
            }
        }

        return $views;
    }

    /**
     * Parse HEK hpc_boundcc polygon string into array of points.
     *
     * HEK format: "POLYGON((x1 y1,x2 y2,x3 y3,...))"
     *
     * @param string $boundcc The hpc_boundcc string from HEK
     * @return array Array of {x, y} points: [{x,y}, {x,y}, ...]
     */
    protected function parseFootprint(string $boundcc): array
    {
        if (empty($boundcc)) {
            return [];
        }

        // Extract coordinates from POLYGON((x1 y1,x2 y2,...))
        if (preg_match('/POLYGON\s*\(\((.+)\)\)/i', $boundcc, $matches)) {
            $coordString = $matches[1];
            $points = [];

            // Split by comma to get individual "x y" pairs
            $pairs = explode(',', $coordString);
            foreach ($pairs as $pair) {
                $pair = trim($pair);
                $coords = preg_split('/\s+/', $pair);
                if (count($coords) >= 2) {
                    $points[] = ['x' => (float) $coords[0], 'y' => (float) $coords[1]];
                }
            }

            return $points;
        }

        return [];
    }

    /**
     * Process HEK event data.
     *
     * @param array $rawRecord Raw event data from HEK
     * @param SourceInterface $source The source object
     *
     * @return Event Processed Event model instance
     */
    public function process(array $rawRecord, SourceInterface $source): Event
    {
        // Get timeline (can be overridden by subclasses)
        $timeline = $this->getTimeLine($rawRecord);

        // Parse footprint from hpc_boundcc
        $footprint = $this->parseFootprint($rawRecord['hpc_boundcc'] ?? '');
        if (!empty($footprint)) {
            $pointCount = count($footprint);
            $this->logger->info("Footprint created | {$pointCount} points | {$rawRecord['event_type']} | {$rawRecord['kb_archivid']}");
        }

        // === DATABASE FIELDS ===
        $eventData = [
            'source_id'         => JsonSource::HEK,
            'path'              => $rawRecord['concept'].'>>'.$rawRecord['frm_name'],
            'start'             => $timeline['start'],
            'peak'              => $timeline['peak'],
            'end'               => $timeline['end'],
            'coordinate_time'   => $timeline['coordinate_time'],
            'hv_hpc_x'          => (float) ($rawRecord['hpc_x'] ?? 0),
            'hv_hpc_y'          => (float) ($rawRecord['hpc_y'] ?? 0),
            'coordinate_system' => 'helioprojective',
            'footprint'         => $footprint,
            'label'             => $this->getLabel($rawRecord),
            'short_label'       => $this->getLabel($rawRecord),
            'legacy_version'    => $rawRecord['frm_specificid'] ?? null,
            'legacy_type'       => $this->eventType,
            'legacy_pin'        => $this->eventType,
        ];

        $event = new Event();
        $event->fill($eventData);

        // === JSON FILES ===
        $event->legacy_views = $this->getViews($rawRecord);
        $event->legacy_link = $this->getLink($rawRecord);

        // === REGION (optional) ===
        if (!empty($rawRecord['ar_noaanum'])) {
            // Handle comma-formatted numbers like "14,035" (can be string or int)
            $regionId = (int) str_replace(',', '', (string) $rawRecord['ar_noaanum']);
            if ($regionId > 0) {
                // Convert 4-digit to 5-digit for post-July-14-2002 events
                $july2002 = strtotime('2002-07-14');
                if ($timeline['start'] >= $july2002 && $regionId < 9000) {
                    $regionId += 10000;
                }
                $event->region_info = [
                    'organization' => 'NOAA',
                    'external_id'  => (string) $regionId
                ];
            }
        }

        return $event;
    }
}
