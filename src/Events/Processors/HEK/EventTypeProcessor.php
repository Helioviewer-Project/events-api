<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\HEK;

use Helioviewer\EventsApi\Events\Processors\BaseProcessor;
use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use Helioviewer\EventsApi\Exception\InvalidEventException;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;
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
class EventTypeProcessor extends BaseProcessor
{
    protected string $eventType;

    public function __construct(
        string $eventType,
        ?LoggerInterface $logger = null,
        ?SentryClientInterface $sentry = null
    ) {
        parent::__construct($logger, $sentry);
        $this->eventType = $eventType;
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
     *
     * coordinate_time is event_starttime for every type, because that is the
     * instant HEK quotes its coordinates for. HEK never states this, but its
     * own data proves it: hgc_x minus hgs_x is L0, so events sharing the
     * instant the coordinates were measured must share that difference.
     * Grouped by start time it is identical across 18,050 groups (spread
     * 0.0090 deg, which is exactly how far L0 moves inside a 60-second
     * bucket); grouped by peak or end it scatters by 12 and 26 degrees.
     *
     * Several subclasses used to override this to store peak or end instead.
     * That was harmless while every event was stored in arcsec — a frame that
     * does not depend on the clock — but a heliographic row is rotated from
     * coordinate_time to the requested time, so an error there is rotated
     * straight into the answer at about 13.2 deg/day.
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
     * The coordinate system this record is stored in, and the fields that go
     * with it.
     *
     * HEK ships every event in three or four systems, but only one of them is
     * what the producer submitted — event_coordsys names it, and HEK derived
     * the rest. That distinction decides everything here: a position submitted
     * as a flat picture coordinate has already lost which side of the Sun it
     * came from, so its heliographic copy is a back-conversion that always
     * lands on the near side. Across the whole archive, 0 of 1,042,918 such
     * events classify as far side. Storing those as degrees would buy no
     * visibility flag and would lose accuracy off the limb, so they keep the
     * arcsec they arrived in.
     *
     * Carrington-declared records are stored as stonyhurst too: HEK fills
     * hgs_x for them at full coverage, both systems describe the same point,
     * and stonyhurst puts its wrap on the far side and reports far-side
     * straight off the row.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array{system: string, x: float, y: float, boundary: string}
     */
    protected function coordinates(array $rawRecord): array
    {
        $declared = $rawRecord['event_coordsys'] ?? '';
        $heliographic = $declared === 'UTC-HGS-TOPO' || $declared === 'UTC-HGC-TOPO';

        // Mirrors Event::hasUsableDegrees(). Storing 'stonyhurst' on degrees
        // that method would reject leaves hv_hpc_x holding degrees the rotator
        // then reads as arcsec — a tiny blob at disc centre.
        $lon = $rawRecord['hgs_x'] ?? null;
        $lat = $rawRecord['hgs_y'] ?? null;
        $usable = is_numeric($lon) && is_numeric($lat) && $lat >= -90 && $lat <= 90;

        if ($heliographic && $usable) {
            return [
                'system'   => 'stonyhurst',
                'x'        => (float) $lon,
                'y'        => (float) $lat,
                // Never fall back to hpc_boundcc here: an arcsec outline under a
                // degree centre would be shifted as though it were degrees.
                'boundary' => (string) ($rawRecord['hgs_boundcc'] ?? ''),
            ];
        }

        // Declared heliographic but the degrees will not support it, so the row
        // silently keeps arcsec and never gets a far-side flag. Rare enough to
        // be worth a line each: a reprocess of the whole archive turned up
        // 7,118 of these, and without this they were invisible — the run
        // reported no failures because nothing had failed.
        if ($heliographic) {
            $this->logger->warning(sprintf(
                'HEK | %s declared %s but hgs_x/hgs_y are unusable (%s, %s) | storing arcsec',
                $rawRecord['kb_archivid'] ?? '?',
                $declared,
                var_export($lon, true),
                var_export($lat, true)
            ));
        }

        return [
            'system'   => 'helioprojective',
            'x'        => (float) ($rawRecord['hpc_x'] ?? 0),
            'y'        => (float) ($rawRecord['hpc_y'] ?? 0),
            'boundary' => (string) ($rawRecord['hpc_boundcc'] ?? ''),
        ];
    }

    /**
     * Parse a HEK boundary polygon string into array of points. The units are
     * whichever system coordinates() picked — degrees for stonyhurst rows,
     * arcsec for helioprojective ones.
     *
     * HEK format: "POLYGON((x1 y1,x2 y2,x3 y3,...))"
     *
     * Returns ONE polygon; process() wraps it into the canonical
     * list-of-polygons footprint shape ([[{x,y},…]]).
     *
     * @param string $boundcc The boundary string from HEK
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
        $timeline = $this->getTimeLine($rawRecord);

        // Store what the producer actually submitted; see coordinates().
        $coords = $this->coordinates($rawRecord);

        $footprint = $this->parseFootprint($coords['boundary']);
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
            'hv_hpc_x'          => $coords['x'],
            'hv_hpc_y'          => $coords['y'],
            'coordinate_system' => $coords['system'],
            // Canonical footprint shape: a LIST of polygons ([[{x,y},…]]) — HEK has one.
            'footprint'         => empty($footprint) ? [] : [$footprint],
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
