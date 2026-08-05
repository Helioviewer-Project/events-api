<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Helioviewer\EventsApi\Utils\TimestampParser;
use Helioviewer\EventsApi\Api\Legacy as LegacyEventResponse;
use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;

class HelioviewerController extends Controller
{
    /**
     * Get events by observation (Legacy format for Helioviewer.org)
     */
    public function getByObservation(Request $request, Response $response, array $args): Response
    {
        $source = strtoupper($args['source']);

        // Validate source
        if (!in_array($source, JsonSource::VALID_SOURCES)) {
            return $this->error($response, 'Invalid source. Must be one of: ' . implode(', ', JsonSource::VALID_SOURCES), 400);
        }

        $timestamp = $args['timestamp'];

        // Parse timestamp using TimestampParser
        try {
            $parsedTimestamp = TimestampParser::parseTimestamp($timestamp);
        } catch (\InvalidArgumentException $e) {
            return $this->error($response, 'Invalid timestamp or date format: ' . $e->getMessage(), 400);
        }

        // Get events that were happening at the specified timestamp using repository
        $events = $this->eventRepository->findActiveAtTime($source, $parsedTimestamp);

        $this->logger->debug("Helioviewer API: Found " . $events->count() . " events for {$source} at " . date('Y-m-d H:i:s', $parsedTimestamp));

        // Rotate all events to target observation time
        $rotatedEvents = $this->coordinateRotator->rotate($events, $parsedTimestamp);

        // Use Legacy formatter to format events
        $legacyResponse = new LegacyEventResponse($this->jsonStorage);
        $formattedEvents = $legacyResponse->formatEvents($source, $rotatedEvents->toArray(), true);

        return $this->json($response, $formattedEvents);
    }

    /**
     * Get batch observations for multiple timestamps, filtered by an explicit
     * selections list (mix of path prefixes and individual event UUIDs).
     *
     * Path-prefix selector matches events whose path equals the prefix OR starts
     * with "prefix>>". UUID selector (last >>-segment matches the UUID pattern)
     * matches that event by id; the breadcrumb prefix is ignored.
     *
     * Route: POST /helioviewer/events/frames_with_selections
     * Body:  { "timestamps": [...], "selections": [...] }
     *
     * Static data is sent once; each timestamp carries only that frame's arcsec
     * offset from it. Clients render pin = center + (dx,dy) and shift every
     * footprint vertex by the same delta.
     *   {
     *     "events":     { "<uuid>": { static fields, arcsec snapshot base } },
     *     "timestamps": { "<ts>":   { "<uuid>": { "dx": ..., "dy": ... } } }
     *   }
     */
    public function getObservationsBySelection(Request $request, Response $response): Response
    {
        // Parse JSON body
        $body = $request->getBody()->getContents();
        $json = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error($response, 'Invalid JSON body', 400);
        }

        // === Validate timestamps ===
        $timestamps = $json['timestamps'] ?? [];
        if (!is_array($timestamps) || empty($timestamps)) {
            return $this->error($response, 'timestamps must be a non-empty array', 400);
        }
        if (count($timestamps) > 150) {
            return $this->error($response, 'timestamps array exceeds maximum of 150 entries. Split into multiple requests.', 400);
        }
        $parsedTimestamps = [];
        foreach ($timestamps as $i => $ts) {
            try {
                $parsedTimestamps[] = TimestampParser::parseTimestamp($ts);
            } catch (\InvalidArgumentException $e) {
                return $this->error($response, "Invalid timestamp at index {$i}: " . $e->getMessage(), 400);
            }
        }

        // === Validate selections ===
        $selections = $json['selections'] ?? [];
        if (!is_array($selections) || empty($selections)) {
            return $this->error($response, 'selections must be a non-empty array', 400);
        }
        if (count($selections) > 200) {
            return $this->error($response, 'selections array exceeds maximum of 200 entries', 400);
        }

        [$pathPrefixes, $uuids] = $this->classifySelections($selections);

        if (empty($pathPrefixes) && empty($uuids)) {
            return $this->error($response, 'no usable path prefixes or UUIDs in selections', 400);
        }

        // === Single DB query: events matching selections AND active at any timestamp ===
        try {
            $allEvents = $this->eventRepository->findActiveAtAnyTimestampForSelections(
                $pathPrefixes,
                $uuids,
                $parsedTimestamps
            );
        } catch (\Exception $e) {
            $this->logger->error("Selection observations DB query failed: " . $e->getMessage());
            $this->sentry->setContext('SelectionObservations', [
                'path_prefixes' => count($pathPrefixes),
                'uuids'         => count($uuids),
                'timestamps'    => count($timestamps),
            ]);
            $this->sentry->capture($e);
            return $this->error($response, 'Failed to query events', 500);
        }

        // Ensure every event carries its native-HPC snapshot (in-memory only, for
        // rows the backfill has not covered yet). The static dict below serves the
        // arcsec base, and resolving once here also spares the per-timestamp clones.
        $needsSnapshot = $allEvents->filter(fn($e) => $e->footprint_hpc === null || $e->x_hpc === null);
        if ($needsSnapshot->isNotEmpty()) {
            $this->hpcResolver->resolve($needsSnapshot);
        }

        // === Build events dict (static fields, same shape as formatEventsBatched) ===
        // Center and footprint are the native-HPC (arcsec) snapshot at the event's
        // own coordinate_time — same units as the per-timestamp centers, so the
        // client's delta shift is valid for every coordinate system (WSA included).
        // Fallback to stored values when a snapshot could not be resolved.
        // Read straight off the model: toArray() would decode every cast attribute
        // for all events, including the stored `footprint` this endpoint only needs
        // when a snapshot is missing.
        $eventsDict = [];
        foreach ($allEvents as $event) {
            $eventsDict[$event->id] = [
                'remote_id'         => $event->remote_id,
                'path'              => $event->path,
                'label'             => $event->label,
                'short_label'       => $event->short_label,
                'start'             => $event->start !== null ? date('Y-m-d\TH:i:s', $event->start) : null,
                'peak'              => $event->peak  !== null ? date('Y-m-d\TH:i:s', $event->peak)  : null,
                'end'               => $event->end   !== null ? date('Y-m-d\TH:i:s', $event->end)   : null,
                'hv_hpc_x'          => $event->x_hpc         ?? $event->hv_hpc_x ?? null,
                'hv_hpc_y'          => $event->y_hpc         ?? $event->hv_hpc_y ?? null,
                'footprint'         => $event->footprint_hpc ?? $event->footprint ?? [],
                'coordinate_system' => $event->coordinate_system,
                'coordinate_time'   => $event->coordinate_time !== null ? date('Y-m-d\TH:i:s', $event->coordinate_time) : null,
                'type'              => $event->legacy_type,
                'pin'               => $event->legacy_pin,
                'version'           => $event->legacy_version,
            ];
        }

        // === Per-timestamp rotated coordinates ===
        $timestampsOut = [];
        foreach ($parsedTimestamps as $idx => $t) {
            $tsKey = $timestamps[$idx]; // original string used as key

            $activeEvents = $allEvents
                ->filter(fn($e) => $e->start <= $t && $e->end >= $t)
                ->map(fn($e) => clone $e);

            if ($activeEvents->isEmpty()) {
                $timestampsOut[$tsKey] = (object) [];
                continue;
            }

            // Remember each center before rotation, to detect rotation failures.
            $centersBeforeRotate = [];
            foreach ($activeEvents as $e) {
                $centersBeforeRotate[$e->id] = [$e->hv_hpc_x, $e->hv_hpc_y];
            }

            // Centers only — footprints were sent once in `events`; the client
            // moves them per frame.
            $rotated = $this->coordinateRotator->rotate($activeEvents, $t, false);

            $obs = [];
            foreach ($rotated as $event) {
                // Center unchanged = rotation did not happen (no snapshot, or
                // coordinator failed): the event stays at its base position.
                $unrotated = [$event->hv_hpc_x, $event->hv_hpc_y] === $centersBeforeRotate[$event->id];

                // dx/dy = rotated center minus the base center (x_hpc/y_hpc —
                // the same values served in `events`).
                $obs[$event->id] = $unrotated
                    ? ['dx' => 0, 'dy' => 0]
                    : [
                        'dx' => $event->hv_hpc_x - $event->x_hpc,
                        'dy' => $event->hv_hpc_y - $event->y_hpc,
                    ];
            }
            $timestampsOut[$tsKey] = $obs;
        }

        return $this->json($response, [
            'events'     => $eventsDict,
            'timestamps' => $timestampsOut,
        ]);
    }

    /**
     * Split selection strings into path prefixes and UUID selectors: a
     * selection whose last >>-segment is a UUID picks that single event by id
     * (the breadcrumb before it is ignored), anything else is a path prefix.
     *
     * @param array $selections Raw selection strings
     * @return array{0: string[], 1: string[]} [pathPrefixes, uuids], deduplicated
     */
    private function classifySelections(array $selections): array
    {
        $uuidPattern = '/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/';
        $pathPrefixes = [];
        $uuids = [];
        foreach ($selections as $sel) {
            if (!is_string($sel) || $sel === '') continue;
            $parts = explode('>>', $sel);
            $last  = end($parts);
            if (preg_match($uuidPattern, $last)) {
                $uuids[] = $last;
            } else {
                $pathPrefixes[] = $sel;
            }
        }
        return [array_values(array_unique($pathPrefixes)), array_values(array_unique($uuids))];
    }

    /**
     * Get event distribution (aggregated counts by time buckets) for multiple paths.
     *
     * Route: POST /helioviewer/distributions/size/{size}/from/{from}/to/{to}
     * Body: { "paths": ["HEK>>Flare", "CCMC>>DONKI>>CME"] }
     *
     * Returns counts grouped by legacy_event_type (FL, CE, AR, etc.) per bucket.
     *
     * @param Request  $request  PSR-7 request
     * @param Response $response PSR-7 response
     * @param array    $args     Route arguments: size, from, to
     *
     * @return Response JSON response with distribution buckets per legacy_event_type
     */
    public function getDistribution(Request $request, Response $response, array $args): Response
    {
        $size = $args['size'];
        $from = $args['from'];
        $to = $args['to'];

        // Validate bucket size
        $validSizes = ['30m', 'h', 'D', 'W', 'M', 'Y'];
        if (!in_array($size, $validSizes)) {
            return $this->error($response, 'Invalid size. Must be one of: ' . implode(', ', $validSizes), 400);
        }

        // Validate timestamps are numeric
        if (!is_numeric($from) || !is_numeric($to)) {
            return $this->error($response, 'from and to must be Unix timestamps in seconds', 400);
        }

        $from = (int) $from;
        $to = (int) $to;

        // Check for milliseconds (13 digits instead of 10)
        if ($from > 10000000000 || $to > 10000000000) {
            return $this->error($response, 'Timestamps appear to be in milliseconds. Please use seconds (10 digits, not 13)', 400);
        }

        // Validate from < to
        if ($from >= $to) {
            return $this->error($response, 'from must be less than to', 400);
        }

        // Parse JSON body for paths
        $body = $request->getBody()->getContents();
        $json = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error($response, 'Invalid JSON body', 400);
        }

        $paths = $json['paths'] ?? [];

        if (!is_array($paths)) {
            return $this->error($response, 'paths must be an array', 400);
        }

        // Filter empty paths
        $paths = array_values(array_filter(
            array_map('trim', $paths),
            fn($p) => $p !== ''
        ));

        if (empty($paths)) {
            return $this->error($response, 'At least one path is required', 400);
        }

        // Query distributions for multiple paths
        try {
            $distributions = $this->distributionRepository->queryMultiple($paths, $size, $from, $to);
        } catch (\Exception $e) {
            $this->logger->error("Distribution query failed: " . $e->getMessage());
            $this->sentry->capture($e);
            return $this->error($response, 'Failed to query distributions', 500);
        }

        // Format response with per-legacy_event_type counts
        $buckets = [];
        $eventTypes = [];
        foreach ($distributions as $start => $counts) {
            $buckets[] = [
                'start' => $start,
                'counts' => $counts,
            ];
            foreach (array_keys($counts) as $type) {
                $eventTypes[$type] = true;
            }
        }

        return $this->json($response, [
            'paths' => $paths,
            'size' => $size,
            'from' => $from,
            'to' => $to,
            'event_types' => array_keys($eventTypes),
            'buckets' => $buckets,
        ]);
    }

    /**
     * Get events by multiple path prefixes and time range.
     *
     * Route: POST /helioviewer/events/from/{from}/to/{to}
     * Body: { "paths": ["HEK>>Flare", "CCMC>>DONKI>>CME"] }
     *
     * A path whose last >>-segment is a UUID (frontend selections carry these,
     * e.g. "HEK>>Flare>><uuid>") selects that single event by id; it still has
     * to overlap the time range.
     *
     * @param Request  $request  PSR-7 request
     * @param Response $response PSR-7 response
     * @param array    $args     Route arguments: from, to
     *
     * @return Response JSON response with matching events
     */
    public function getEventsByPaths(Request $request, Response $response, array $args): Response
    {
        $from = $args['from'] ?? '';
        $to = $args['to'] ?? '';

        // Validate timestamps are numeric
        if (!is_numeric($from) || !is_numeric($to)) {
            return $this->error($response, 'from and to must be Unix timestamps in seconds', 400);
        }

        $from = (int) $from;
        $to = (int) $to;

        // Check for milliseconds (13 digits instead of 10)
        // Unix timestamps > 10000000000 are likely milliseconds (year 2286+ in seconds)
        if ($from > 10000000000 || $to > 10000000000) {
            return $this->error($response, 'Timestamps appear to be in milliseconds. Please use seconds (10 digits, not 13)', 400);
        }

        // Check for reasonable range (year 1970 to 2200)
        $minTimestamp = 0;              // 1970-01-01
        $maxTimestamp = 7258118400;     // 2200-01-01
        if ($from < $minTimestamp || $to < $minTimestamp || $from > $maxTimestamp || $to > $maxTimestamp) {
            return $this->error($response, 'Timestamps must be between 1970 and 2200', 400);
        }

        if ($from >= $to) {
            return $this->error($response, 'from must be less than to', 400);
        }

        // Parse JSON body for paths
        $body = $request->getBody()->getContents();
        $json = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->error($response, 'Invalid JSON body', 400);
        }

        $paths = $json['paths'] ?? [];

        if (!is_array($paths)) {
            return $this->error($response, 'paths must be an array', 400);
        }

        // Filter empty paths
        $paths = array_filter(
            array_map('trim', $paths),
            fn($p) => $p !== ''
        );

        if (empty($paths)) {
            return $this->error($response, 'At least one path prefix is required', 400);
        }

        // Frontend selections may carry "path>>uuid" entries — split those off
        // as id selectors.
        [$pathPrefixes, $uuids] = $this->classifySelections($paths);

        try {
            $events = $this->eventRepository->findByPathPrefixesAndTimeRange($pathPrefixes, $from, $to, $uuids);

            // Format events for Helioviewer (custom format per source)
            $formattedEvents = array_map(fn($e) => $this->formatEventForHelioviewerEventTimeline($e), $events);

            return $this->json($response, [
                'paths' => array_values($paths),
                'from' => $from,
                'to' => $to,
                'count' => count($formattedEvents),
                'events' => $formattedEvents,
            ]);
        } catch (\Exception $e) {
            $this->logger->error("getEventsByPaths failed: " . $e->getMessage());
            $this->sentry->capture($e);
            return $this->error($response, 'Failed to query events', 500);
        }
    }

    /**
     * Format event for Helioviewer.org Event Timeline.
     * Formats differently based on source_id (HEK, CCMC, RHESSI).
     *
     * @param Event $event The event to format
     * @return array Formatted event data
     */
    private function formatEventForHelioviewerEventTimeline(Event $event): array
    {
        $uuid = $event->id;

        // Load source JSON for additional fields
        $source = $this->jsonStorage->load("/u/apps/data/sources/{$uuid}.json") ?: [];

        // Base fields common to all sources
        $formatted = [
            'id' => $uuid,
            'x' => $event->start * 1000,           // milliseconds
            'x2' => $event->end * 1000,            // milliseconds
            'y' => 1,
            'event_starttime' => date('Y-m-d H:i:s', $event->start),
            'event_endtime' => date('Y-m-d H:i:s', $event->end),
            'event_peaktime' => $event->peak ? date('Y-m-d H:i:s', $event->peak) : null,
            'hv_hpc_x' => $event->hv_hpc_x,
            'hv_hpc_y' => $event->hv_hpc_y,
            'url' => $event->getUrl(),
            'source_url' => $event->getUrl() . '/source',
            'modifier' => 0,
        ];

        // $formatted['source'] = $source;

        // Format based on source_id
        if ($event->source_id === JsonSource::HEK) {
            $formatted['kb_archivid'] = str_replace("ivo://helio-informatics.org/", "", $source['kb_archivid'] ?? $event->remote_id);
            $formatted['hv_labels_formatted'] = $this->buildHekLabelArray($source);
            $formatted['event_type'] = $source['event_type'] ?? $event->legacy_type;
            $formatted['frm_name'] = $source['frm_name'] ?? '';
            $formatted['frm_specificid'] = $source['frm_specificid'] ?? '';
            $formatted['concept'] = $source['concept'] ?? $event->label;
        }

        if ($event->source_id === JsonSource::CCMC) {
            $pathParts = explode('>>', $event->path);

            if ($event->path === 'CCMC>>DONKI>>CME') {
                $formatted['kb_archivid'] = $source['activityID'] ?? $event->remote_id;
                $formatted['frm_name'] = 'DONKI';
                $formatted['frm_specificid'] = $source['activityID'] ?? '';
                $formatted['concept'] = 'CME';
            } elseif ($event->path === 'CCMC>>DONKI>>Solar Flares') {
                $formatted['kb_archivid'] = $source['flrID'] ?? $event->remote_id;
                $formatted['frm_name'] = 'DONKI';
                $formatted['frm_specificid'] = $source['flrID'] ?? '';
                $formatted['concept'] = 'Flare';
            } elseif (str_starts_with($event->path, 'CCMC>>Solar Flare Predictions>>') && count($pathParts) === 3) {
                $kbArchivid = hash('sha256', json_encode($source));
                $formatted['kb_archivid'] = $kbArchivid;
                $formatted['frm_name'] = $pathParts[2];
                $formatted['frm_specificid'] = $kbArchivid;
                $formatted['concept'] = 'Flare Prediction';
            } else {
                $formatted['kb_archivid'] = $event->remote_id ?? $uuid;
                $formatted['frm_name'] = '';
                $formatted['frm_specificid'] = '';
                $formatted['concept'] = $source['concept'] ?? $event->label;
            }

            $formatted['hv_labels_formatted'] = [];
            $formatted['event_type'] = $source['event_type'] ?? $event->legacy_type;
        }

        if ($event->source_id === JsonSource::RHESSI) {
            if (str_starts_with($event->path, 'RHESSI>>Solar Flares>>Flare')) {
                $formatted['kb_archivid'] = $source['id'] ?? $event->remote_id;
                $formatted['frm_name'] = 'RHESSI';
                $formatted['frm_specificid'] = $source['id'] ?? '';
                $formatted['concept'] = 'Flare';
            } else {
                $formatted['kb_archivid'] = $event->remote_id ?? $uuid;
                $formatted['frm_name'] = 'RHESSI';
                $formatted['frm_specificid'] = '';
                $formatted['concept'] = $source['concept'] ?? $event->label;
            }

            $formatted['hv_labels_formatted'] = [];
            $formatted['event_type'] = $source['event_type'] ?? $event->legacy_type;
        }

        if ($event->source_id === JsonSource::WSA) {
            // Path: WSA>>{product}>>{sat}>>{input_map} — product names the concept,
            // sat + input map identify the "method" (mirrors the FRM role elsewhere).
            $pathParts = explode('>>', $event->path);

            $formatted['kb_archivid'] = $event->remote_id ?? $uuid;
            $formatted['frm_name'] = implode(' ', array_slice($pathParts, 2)) ?: 'WSA';
            $formatted['frm_specificid'] = '';
            $formatted['concept'] = $pathParts[1] ?? $event->label;
            $formatted['hv_labels_formatted'] = [];
            // 'CH' (Coronal Hole) / 'MC' (Magnetic Connectivity) — without this the
            // helioviewer API renders the series as event_type "UNK".
            $formatted['event_type'] = $event->legacy_type;
        }

        return $formatted;
    }

    /**
     * Build HEK event label array for display.
     *
     * @param array $source HEK event source data
     * @return array Associative array of label key/value pairs
     */
    private function buildHekLabelArray(array $source): array
    {
        $labelArray = [];
        $eventType = $source['event_type'] ?? '';
        $frmName = $source['frm_name'] ?? '';

        if ($eventType == 'AR') {
            if ($frmName == 'HMI SHARP') {
                $labelArray['HMI SHARP Identifier'] = 'HMI SHARP ' . ($source['frm_specificid'] ?? '');
            } elseif ($frmName == 'NOAA SWPC Observer') {
                $labelArray['NOAA Number'] = 'NOAA ' . ($source['ar_noaanum'] ?? '');

                $arMtwilsoncls = $source['ar_mtwilsoncls'] ?? '';
                if (preg_match_all('/(ALPHA|BETA|GAMMA)/', $arMtwilsoncls, $matches) > 0) {
                    $arMtwilsoncls = implode('', $matches[0]);
                    $arMtwilsoncls = str_replace(
                        ['ALPHA', 'BETA', 'GAMMA'],
                        ['α', 'β', 'γ'],
                        $arMtwilsoncls
                    );
                }
                $labelArray['Mt. Wilson Class.'] = $arMtwilsoncls;
            } elseif ($frmName == 'SPoCA') {
                $tmpArr = explode('_', $source['frm_specificid'] ?? '');
                $labelArray['SPoCA Identifier'] = 'SPoCA ' . ltrim(array_pop($tmpArr), '0');
            } elseif ($frmName == 'SolarMonitor Active Region Tracker (SMART)') {
                $labelArray['SMART Identifier'] = 'SMART ' . ($source['frm_specificid'] ?? '');
            }
        } elseif ($eventType == 'CE') {
            if ($frmName == 'CACTus (Computer Aided CME Tracking)') {
                $labelArray['Radial Lin. Vel.'] =
                    ($source['cme_radiallinvel'] ?? '') . ' ± ' .
                    ($source['cme_radiallinvelstddev'] ?? '') . ' ' .
                    ($source['cme_radiallinvelunit'] ?? '');
                $labelArray['Angular Width'] =
                    ($source['cme_angularwidth'] ?? '') . ' ' .
                    ($source['cme_angularwidthunit'] ?? '');
            } elseif ($frmName == 'CDAW_GopalswamyYashiroFreeland') {
                $labelArray['Radial Lin. Vel.'] =
                    ($source['cme_radiallinvel'] ?? '') . ' ' .
                    ($source['cme_radiallinvelunit'] ?? '');
                $labelArray['Angular Width'] =
                    ($source['cme_angularwidth'] ?? '') . ' ' .
                    ($source['cme_angularwidthunit'] ?? '');
                $labelArray['Mass'] =
                    ($source['cme_mass'] ?? '') . ' ' .
                    ($source['cme_massunit'] ?? '');
            }
        } elseif ($eventType == 'CH') {
            if ($frmName == 'LMSAL forecaster + SSW PFSS package' ||
                $frmName == 'LMSAL forecaster 2 + SSW PFSS package') {
                $areaAtDiskCenter = $source['area_atdiskcenter'] ?? null;
                if ($areaAtDiskCenter !== null) {
                    $labelArray['Area at Disk Center'] =
                        str_replace('+', '', sprintf('%.1e', (float)$areaAtDiskCenter)) .
                        ' ' . str_replace('2', '²', $source['area_unit'] ?? '');
                }
            } elseif ($frmName == 'SPoCA') {
                $tmpArr = explode('_', $source['frm_specificid'] ?? '');
                $labelArray['SPoCA Identifier'] = 'SPoCA ' . ltrim(array_pop($tmpArr), '0');
            }
        } elseif ($eventType == 'EF') {
            if ($frmName == 'Emerging flux region module') {
                $areaAtDiskCenter = $source['area_atdiskcenter'] ?? null;
                $areaUncert = $source['area_atdiskcenteruncert'] ?? null;
                if ($areaAtDiskCenter !== null && $areaUncert !== null) {
                    $labelArray['Area at Disk Center'] =
                        str_replace('+', '', sprintf('%.1e', (float)$areaAtDiskCenter)) .
                        ' ± ' .
                        str_replace('+', '', sprintf('%.1e', (float)$areaUncert)) .
                        ' ' . str_replace('2', '²', $source['area_unit'] ?? '');
                }
                $posPeakFlux = $source['ef_pospeakfluxonsetrate'] ?? null;
                $onsetRateUnit = $source['ef_onsetrateunit'] ?? null;
                if ($posPeakFlux !== null && $onsetRateUnit !== null) {
                    $labelArray['Peak Pos. Flux Onset'] =
                        round((float)$posPeakFlux, 1) . ' ' . $onsetRateUnit;
                }
                $negPeakFlux = $source['ef_negpeakfluxonsetrate'] ?? null;
                if ($negPeakFlux !== null && $onsetRateUnit !== null) {
                    $labelArray['Peak Neg. Flux Onset'] =
                        round((float)$negPeakFlux, 1) . ' ' . $onsetRateUnit;
                }
            }
        } elseif ($eventType == 'FI') {
            if ($frmName == 'AAFDCC') {
                $fiLength = $source['fi_length'] ?? null;
                if ($fiLength !== null) {
                    $labelArray['Filament Length'] =
                        str_replace('+', '', sprintf('%.1e', (float)$fiLength)) .
                        ' ' . ($source['fi_lengthunit'] ?? '');
                }
            }
        } elseif ($eventType == 'FL') {
            if ($frmName == 'SEC standard') {
                $labelArray['GOES Class'] = $source['fl_goescls'] ?? '';
            } elseif ($frmName == 'Flare Detective - Trigger Module') {
                $peakFlux = $source['fl_peakflux'] ?? null;
                if ($peakFlux !== null) {
                    $labelArray['Peak Flux'] =
                        round((float)$peakFlux, 1) . ' ' . ($source['fl_peakfluxunit'] ?? '');
                }
            } elseif ($frmName == 'SWPC') {
                $labelArray['GOES Class'] = $source['fl_goescls'] ?? '';
            }
        } elseif ($eventType == 'SG') {
            if ($frmName == 'Sigmoid Sniffer') {
                $labelArray['Shape'] = $source['sg_shape'] ?? '';
            }
        } else {
            $labelArray['Event Type'] = $source['concept'] ?? '';
        }

        return $labelArray;
    }
}
