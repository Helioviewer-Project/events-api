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
     * Get event distribution (aggregated counts by time buckets)
     *
     * @param Request  $request  PSR-7 request
     * @param Response $response PSR-7 response
     * @param array    $args     Route arguments: path, size, start, end
     *
     * @return Response JSON response with distribution buckets
     */
    public function getDistribution(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'];
        $size = $args['size'];
        $start = $args['start'];
        $end = $args['end'];

        // Validate bucket size
        $validSizes = ['30m', 'h', 'D', 'W', 'M', 'Y'];
        if (!in_array($size, $validSizes)) {
            return $this->error($response, 'Invalid size. Must be one of: ' . implode(', ', $validSizes), 400);
        }

        // Validate timestamps are numeric
        if (!is_numeric($start) || !is_numeric($end)) {
            return $this->error($response, 'Start and end must be Unix timestamps in seconds', 400);
        }

        $start = (int) $start;
        $end = (int) $end;

        // Check for milliseconds (13 digits instead of 10)
        if ($start > 10000000000 || $end > 10000000000) {
            return $this->error($response, 'Timestamps appear to be in milliseconds. Please use seconds (10 digits, not 13)', 400);
        }

        // Check for reasonable range (year 1970 to 2200)
        if ($start < 0 || $end < 0 || $start > 7258118400 || $end > 7258118400) {
            return $this->error($response, 'Timestamps must be between 1970 and 2200', 400);
        }

        // Validate start < end
        if ($start >= $end) {
            return $this->error($response, 'Start must be less than end', 400);
        }

        // Query distributions
        try {
            $distributions = $this->distributionRepository->query($path, $size, $start, $end);
        } catch (\Exception $e) {
            $this->logger->error("Distribution query failed: " . $e->getMessage());
            return $this->error($response, 'Failed to query distributions', 500);
        }

        // Format response
        $buckets = $distributions->map(fn($dist) => [
            'start' => $dist->start,
            'count' => $dist->count,
        ])->values()->toArray();

        return $this->json($response, [
            'path' => $path,
            'size' => $size,
            'start' => $start,
            'end' => $end,
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
            $formattedEvents = array_map(fn($e) => $this->formatEventForHelioviewer($e), $events);

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
     * Format event for Helioviewer.org frontend.
     * Formats differently based on source_id (HEK, CCMC, WSA, RHESSI).
     *
     * @param Event $event The event to format
     * @return array Formatted event data
     */
    private function formatEventForHelioviewer(Event $event): array
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
        ];

        // Format based on source_id
        switch ($event->source_id) {
            case self::SOURCE_HEK:
                $formatted = array_merge($formatted, $this->formatHekEvent($event, $source));
                break;

            case self::SOURCE_CCMC:
                $formatted = array_merge($formatted, $this->formatCcmcEvent($event, $source));
                break;

            case self::SOURCE_RHESSI:
                $formatted = array_merge($formatted, $this->formatRhessiEvent($event, $source));
                break;

            case self::SOURCE_WSA:
                $formatted = array_merge($formatted, $this->formatWsaEvent($event, $source));
                break;

            default:
                // Unknown source - include basic fields
                $formatted['source_id'] = $event->source_id;
                $formatted['remote_id'] = $event->remote_id;
                $formatted['path'] = $event->path;
                $formatted['label'] = $event->label;
        }

        return $formatted;
    }

    /**
     * Format HEK-specific event fields.
     */
    private function formatHekEvent(Event $event, array $source): array
    {
        return [
            'kb_archivid' => $event->remote_id,
            'hv_labels_formatted' => $source['hv_labels_formatted'] ?? [],
            'event_type' => $source['event_type'] ?? $event->legacy_type,
            'frm_name' => $source['frm_name'] ?? null,
            'frm_specificid' => $source['frm_specificid'] ?? '',
            'concept' => $source['concept'] ?? $event->label,
            'modifier' => 0,
            'color' => $source['color'] ?? '#e68188',
        ];
    }

    /**
     * Format CCMC-specific event fields.
     */
    private function formatCcmcEvent(Event $event, array $source): array
    {
        return [
            'activity_id' => $event->remote_id,
            'event_type' => $source['event_type'] ?? $event->legacy_type,
            'concept' => $source['concept'] ?? $event->label,
            'instruments' => $source['instruments'] ?? [],
            'linked_events' => $source['linkedEvents'] ?? [],
            'modifier' => 0,
            'color' => $source['color'] ?? '#3498db',
        ];
    }

    /**
     * Format RHESSI-specific event fields.
     */
    private function formatRhessiEvent(Event $event, array $source): array
    {
        return [
            'rhessi_id' => $event->remote_id,
            'event_type' => $source['event_type'] ?? $event->legacy_type,
            'concept' => $source['concept'] ?? $event->label,
            'energy_kev' => $source['energy_kev'] ?? [],
            'peak_count_rate' => $source['peak_count_rate'] ?? null,
            'modifier' => 0,
            'color' => $source['color'] ?? '#9b59b6',
        ];
    }

    /**
     * Format WSA-specific event fields.
     */
    private function formatWsaEvent(Event $event, array $source): array
    {
        return [
            'wsa_id' => $event->remote_id,
            'event_type' => $source['event_type'] ?? $event->legacy_type,
            'concept' => $source['concept'] ?? $event->label,
            'modifier' => 0,
            'color' => $source['color'] ?? '#27ae60',
        ];
    }
}
