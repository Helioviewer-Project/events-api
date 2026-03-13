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
    // Source ID constants
    private const SOURCE_CCMC = 1;
    private const SOURCE_HEK = 2;
    private const SOURCE_WSA = 3;
    private const SOURCE_RHESSI = 4;
    /**
     * Get events by observation (Legacy format for Helioviewer.org)
     */
    public function getByObservation(Request $request, Response $response, array $args): Response
    {
        $source = strtoupper($args['source']);

        // Validate source
        if (!in_array($source, ['CCMC', 'HEK', 'WSA', 'RHESSI'])) {
            return $this->error($response, 'Invalid source. Must be one of: CCMC, HEK, WSA, RHESSI', 400);
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

        // Check for reasonable range (year 1970 to 2200)
        if ($from < 0 || $to < 0 || $from > 7258118400 || $to > 7258118400) {
            return $this->error($response, 'Timestamps must be between 1970 and 2200', 400);
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
            return $this->error($response, 'Failed to query events', 500);
        }
    }

    /**
     * Format event for Helioviewer.org Event Timeline.
     * Formats differently based on source_id (HEK, CCMC, WSA, RHESSI).
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
        if ($event->source_id === self::SOURCE_HEK) {
            $formatted['kb_archivid'] = str_replace("ivo://helio-informatics.org/", "", $source['kb_archivid'] ?? $event->remote_id);
            $formatted['hv_labels_formatted'] = $this->buildHekLabelArray($source);
            $formatted['event_type'] = $source['event_type'] ?? $event->legacy_type;
            $formatted['frm_name'] = $source['frm_name'] ?? '';
            $formatted['frm_specificid'] = $source['frm_specificid'] ?? '';
            $formatted['concept'] = $source['concept'] ?? $event->label;
        }

        if ($event->source_id === self::SOURCE_CCMC) {
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

        if ($event->source_id === self::SOURCE_RHESSI) {
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
