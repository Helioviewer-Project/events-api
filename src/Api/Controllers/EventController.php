<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Helioviewer\EventsApi\Utils\TimestampParser;
use Helioviewer\EventsApi\Api\Legacy as LegacyEventResponse;
use Helioviewer\EventsApi\Events\Sources\JsonSource;

class EventController extends Controller
{
    /**
     * Get recent events
     */
    public function getRecents(Request $request, Response $response): Response
    {
        try {
            $events = $this->eventRepository->getRecent(100);

            // Enhance events with source, views, links, and regions data
            $enhancedEvents = array_map(function ($event) {
                // Convert Event object to array (includes regions from eager loading)
                $eventArray = is_array($event) ? $event : $event->toArray();
                $uuid = $eventArray['id'];

                // Format timestamps (replace raw values)
                foreach (['start', 'end', 'peak', 'coordinate_time'] as $field) {
                    if (!empty($eventArray[$field])) {
                        $eventArray[$field] = $this->formatTimestamp($eventArray[$field]);
                    }
                }
                // created_at and updated_at are already formatted by Eloquent

                // Load source JSON data
                $sourceData = $this->jsonStorage->load("/u/apps/data/sources/{$uuid}.json");
                $eventArray['source'] = $sourceData ?: null;

                // Load views JSON data
                $viewsData = $this->jsonStorage->load("/u/apps/data/views/{$uuid}.json");
                $eventArray['views'] = $viewsData ?: [];

                // Load links JSON data
                $linksData = $this->jsonStorage->load("/u/apps/data/links/{$uuid}.json");
                // Links can be either an array or object, preserve what's loaded
                $eventArray['link'] = $linksData;

                // Add API links - replace id with url, replace source with source_url
                $apiUrl = rtrim($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', '/');

                // Replace id with url and source with source_url
                $reorderedArray = [];
                foreach ($eventArray as $key => $value) {
                    if ($key === 'id') {
                        $reorderedArray['url'] = "{$apiUrl}/api/v2/events/{$uuid}";
                    } elseif ($key === 'source') {
                        $reorderedArray['source_url'] = "{$apiUrl}/api/v2/events/{$uuid}/source";
                    } else {
                        $reorderedArray[$key] = $value;
                    }
                }

                return $reorderedArray;
            }, $events);

            return $this->json($response, $enhancedEvents);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get recent events: " . $e->getMessage());
            return $this->error($response, 'Failed to retrieve recent events');
        }
    }

    /**
     * Get events by observation (V1 API)
     */
    public function getByObservationV1(Request $request, Response $response, array $args): Response
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
        $eventsArray = $this->eventRepository->findActiveAtTime($source, $parsedTimestamp);
        $this->logger->debug("API v1: Found " . count($eventsArray) . " events for {$source} at " . date('Y-m-d H:i:s', $parsedTimestamp));

        // Filter out events with invalid latitude values and track them
        $validEvents = [];

        foreach ($eventsArray as $event) {
            $lat = $event['hv_hpc_y'];

            // Check if latitude is outside valid range (-90 to 90)
            if ($lat < -90 || $lat > 90) {
                $this->logger->warning("Event has invalid latitude {$lat}: /api/v2/events/{$event['id']}/source");
            } else {
                $validEvents[] = $event; // Keep original index for coordinate mapping
            }
        }

        // Create plucked array with lat, lon, and coordinate_time for batch processing
        $pluckedArray = [];
        foreach ($validEvents as $index => $event) {
            $pluckedArray[$index] = [
                'lon' => $event['hv_hpc_x'],
                'lat' => $event['hv_hpc_y'],
                'coordinate_time' => $event['coordinate_time'],
            ];
        }

        $rotatedCoordinates = null;

        // Batch rotate coordinates using plucked array
        try {
            $startTime = microtime(true);
            $rotatedCoordinates = $this->coordinator->stonyhurstToHelioprojectiveBatch($pluckedArray, $parsedTimestamp);
            $duration = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds
            $this->logger->debug("API v1: HttpCoordinator succeeded for " . count($pluckedArray) . " coordinates in {$duration}ms");

        } catch (\Helioviewer\EventsApi\Coordinator\CoordinatorException $e) {

            $this->logger->warning("API v1: HttpCoordinator failed: " . $e->getMessage());
            // Fallback to backup coordinator (CommandLineCoordinator)
            try {
                $startTime = microtime(true);
                $rotatedCoordinates = $this->backupCoordinator->stonyhurstToHelioprojectiveBatch($pluckedArray, $parsedTimestamp);
                $duration = round((microtime(true) - $startTime) * 1000, 2); // Convert to milliseconds
                $this->logger->debug("API v1: CommandLineCoordinator fallback succeeded in {$duration}ms");
            } catch (\Helioviewer\EventsApi\Coordinator\CoordinatorException $backupError) {
                // Both coordinators failed
                $this->logger->error("API v1: Both coordinators failed: " . $backupError->getMessage());
            }
        }

        // If both coordinators failed, return error
        if ($rotatedCoordinates === null) {
            return $this->error($response, 'Coordinate transformation service unavailable', 500);
        }

        // Apply rotated coordinates to valid events ensuring same ordering
        $eventsWithRotatedCoords = [];
        foreach ($validEvents as $index => $event) {
            // Add rotated coordinates using the same index to ensure ordering
            if (isset($rotatedCoordinates[$index])) {
                // Map hpc_x/hpc_y to hv_hpc_x/hv_hpc_y for backwards compatibility
                $rotated = $rotatedCoordinates[$index];
                $event['hv_hpc_x'] = $rotated['hpc_x'] ?? null;
                $event['hv_hpc_y'] = $rotated['hpc_y'] ?? null;
            }

            $eventsWithRotatedCoords[] = $event;
        }

        // Use Legacy formatter to format events
        $legacyResponse = new LegacyEventResponse($this->jsonStorage);
        $formattedEvents = $legacyResponse->formatEvents($eventsWithRotatedCoords, true);

        return $this->json($response, $formattedEvents);
    }

    /**
     * Get events by observation (V2 API)
     */
    public function getByObservation(Request $request, Response $response, array $args): Response
    {
        try {
            $source = $args['source'];
            $timestamp = TimestampParser::parseTimestamp($args['timestamp']);

            // Map source to source_id
            $sourceMaps = [
                'ccmc.donki' => JsonSource::CCMC,
                'ccmc.gong' => JsonSource::CCMC,
                'ccmc' => JsonSource::CCMC,
                'rhessi' => JsonSource::RHESSI,
                'hek' => JsonSource::HEK,
            ];

            if (!isset($sourceMaps[$source])) {
                return $this->error($response, "Unknown source: {$source}", 400);
            }

            $sourceId = $sourceMaps[$source];

            // Query events within 6 hours before and after timestamp
            $startTime = $timestamp - (6 * 3600);
            $endTime = $timestamp + (6 * 3600);

            $events = $this->eventRepository->findActiveAtTime($source, $timestamp);

            // Coordinators are used for coordinate transformation, not needed here
            $coordinates = null;

            // Format events
            $formattedEvents = array_map(function ($event) {
                $eventArray = $event->toArray();
                $uuid = $eventArray['id'];

                // Format timestamps
                foreach (['start', 'end', 'peak', 'coordinate_time'] as $field) {
                    if (!empty($eventArray[$field])) {
                        $eventArray[$field] = $this->formatTimestamp($eventArray[$field]);
                    }
                }

                // Load source JSON data
                $sourceData = $this->jsonStorage->load("/u/apps/data/sources/{$uuid}.json");
                $eventArray['source'] = $sourceData ?: null;

                // Load views JSON data
                $viewsData = $this->jsonStorage->load("/u/apps/data/views/{$uuid}.json");
                $eventArray['views'] = $viewsData ?: [];

                // Load links JSON data
                $linksData = $this->jsonStorage->load("/u/apps/data/links/{$uuid}.json");
                $eventArray['link'] = $linksData;

                return $eventArray;
            }, $events);

            $result = [
                'observation' => [
                    'timestamp' => $timestamp,
                    'coordinates' => $coordinates,
                ],
                'events' => $formattedEvents,
                'count' => count($formattedEvents),
            ];

            return $this->json($response, $result);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get events by observation V2: " . $e->getMessage());
            return $this->error($response, 'Failed to retrieve events');
        }
    }

    /**
     * Get event by UUID
     */
    public function getByUuid(Request $request, Response $response, array $args): Response
    {
        try {
            $uuid = $args['uuid'];

            $event = $this->eventRepository->findById($uuid);

            if (!$event) {
                return $this->error($response, 'Event not found', 404);
            }

            // Convert to array and enhance
            $eventArray = $event->toArray();

            // Format timestamps
            foreach (['start', 'end', 'peak', 'coordinate_time'] as $field) {
                if (!empty($eventArray[$field])) {
                    $eventArray[$field] = $this->formatTimestamp($eventArray[$field]);
                }
            }

            // Load source JSON data
            $sourceData = $this->jsonStorage->load("/u/apps/data/sources/{$uuid}.json");
            $eventArray['source'] = $sourceData ?: null;

            // Load views JSON data
            $viewsData = $this->jsonStorage->load("/u/apps/data/views/{$uuid}.json");
            $eventArray['views'] = $viewsData ?: [];

            // Load links JSON data
            $linksData = $this->jsonStorage->load("/u/apps/data/links/{$uuid}.json");
            $eventArray['link'] = $linksData;

            return $this->json($response, $eventArray);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get event by UUID: " . $e->getMessage());
            return $this->error($response, 'Failed to retrieve event');
        }
    }

    /**
     * Get event source by UUID
     */
    public function getEventSource(Request $request, Response $response, array $args): Response
    {
        try {
            $uuid = $args['uuid'];

            $event = $this->eventRepository->findById($uuid);

            if (!$event) {
                return $this->error($response, 'Event not found', 404);
            }

            // Load source JSON data
            $sourceData = $this->jsonStorage->load("/u/apps/data/sources/{$uuid}.json");

            if (!$sourceData) {
                return $this->error($response, 'Source data not found', 404);
            }

            return $this->json($response, $sourceData);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get event source: " . $e->getMessage());
            return $this->error($response, 'Failed to retrieve source data');
        }
    }
}
