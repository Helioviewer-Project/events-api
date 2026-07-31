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
     * Response:
     *   {
     *     "events":     { "<uuid>": { static fields } },
     *     "timestamps": { "<ts>":   { "<uuid>": { "hv_hpc_x": ..., "hv_hpc_y": ... } } }
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

        // Classify each selection: trailing UUID-like segment → uuid, else path prefix
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
        $pathPrefixes = array_values(array_unique($pathPrefixes));
        $uuids        = array_values(array_unique($uuids));

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

        // === Build events dict (static fields, same shape as formatEventsBatched) ===
        $eventsDict = [];
        foreach ($allEvents as $event) {
            $arr  = $event->toArray();
            $uuid = $arr['id'];
            $eventsDict[$uuid] = [
                'remote_id'         => $arr['remote_id']     ?? null,
                'path'              => $arr['path']          ?? null,
                'label'             => $arr['label']         ?? null,
                'short_label'       => $arr['short_label']   ?? null,
                'start'             => isset($arr['start']) ? date('Y-m-d\TH:i:s', $arr['start']) : null,
                'peak'              => isset($arr['peak'])  ? date('Y-m-d\TH:i:s', $arr['peak'])  : null,
                'end'               => isset($arr['end'])   ? date('Y-m-d\TH:i:s', $arr['end'])   : null,
                'hv_hpc_x'          => $arr['hv_hpc_x']      ?? null,
                'hv_hpc_y'          => $arr['hv_hpc_y']      ?? null,
                'footprint'         => $arr['footprint']     ?? [],
                'coordinate_system' => $arr['coordinate_system'] ?? null,
                'coordinate_time'   => isset($arr['coordinate_time']) ? date('Y-m-d\TH:i:s', $arr['coordinate_time']) : null,
                'type'              => $arr['legacy_type']    ?? null,
                'pin'               => $arr['legacy_pin']     ?? null,
                'version'           => $arr['legacy_version'] ?? null,
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

            $rotated = $this->coordinateRotator->rotate($activeEvents, $t);

            $obs = [];
            foreach ($rotated as $event) {
                $obs[$event->id] = [
                    'hv_hpc_x' => $event->hv_hpc_x,
                    'hv_hpc_y' => $event->hv_hpc_y,
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

        $pathPrefixes = $json['paths'] ?? [];

        if (!is_array($pathPrefixes)) {
            return $this->error($response, 'paths must be an array', 400);
        }

        // Filter empty paths
        $pathPrefixes = array_filter(
            array_map('trim', $pathPrefixes),
            fn($p) => $p !== ''
        );

        if (empty($pathPrefixes)) {
            return $this->error($response, 'At least one path prefix is required', 400);
        }

        try {
            $events = $this->eventRepository->findByPathPrefixesAndTimeRange($pathPrefixes, $from, $to);

            // Format events for Helioviewer (custom format per source)
            $formattedEvents = array_map(fn($e) => $this->formatEventForHelioviewerEventTimeline($e), $events);

            return $this->json($response, [
                'paths' => $pathPrefixes,
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
