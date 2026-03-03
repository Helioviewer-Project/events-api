<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Helioviewer\EventsApi\Utils\TimestampParser;
use Helioviewer\EventsApi\Api\Legacy as LegacyEventResponse;

class HelioviewerController extends Controller
{
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
            return $this->error($response, 'Start and end must be Unix timestamps', 400);
        }

        $start = (int) $start;
        $end = (int) $end;

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
}
