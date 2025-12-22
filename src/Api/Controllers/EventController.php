<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Helioviewer\EventsApi\Utils\TimestampParser;
use Helioviewer\EventsApi\Api\Legacy as LegacyEventResponse;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Events\Event;

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


    public function rotateAllEvents(array $eventsArray, int $targetTimestamp): array
    {
        // Filter stonyhurst events with valid coordinates
        $stonyhurstCoords = array_reduce(
            array_filter($eventsArray, fn($e) => ($e['coordinate_system'] ?? null) === 'stonyhurst'),
            function ($carry, $event) {
                $lat = $event['hv_hpc_y'];
                if ($lat >= -90 && $lat <= 90) {
                    $carry[$event['id']] = [
                        'lat' => $event['hv_hpc_y'],
                        'lon' => $event['hv_hpc_x'],
                        'coordinate_time' => $event['coordinate_time'],
                    ];
                }
                return $carry;
            },
            []
        );


        // Transform stonyhurst coordinates
        $stonyhurstRotatedCoordinates = [];
        if (!empty($stonyhurstCoords)) {
            try {
                $stonyhurstRotatedCoordinates = $this->coordinator->stonyhurstToHelioprojectiveBatch($stonyhurstCoords, $targetTimestamp);
            } catch (\Helioviewer\EventsApi\Coordinator\CoordinatorException $e) {
                $this->logger->warning("API v1: HttpCoordinator failed process stonyhurstCoords : " . $e->getMessage());
                try {
                    $stonyhurstRotatedCoordinates = $this->backupCoordinator->stonyhurstToHelioprojectiveBatch($stonyhurstCoords, $targetTimestamp);
                } catch (\Helioviewer\EventsApi\Coordinator\CoordinatorException $backupError) {
                    $this->logger->error("API v1: Both coordinators failed process stonyhurstCoords  " . $backupError->getMessage());
                }
            }
        }


        // Filter helioprojective events (prepared for future use)
        $helioprojectiveCoords = array_reduce(
            array_filter($eventsArray, fn($e) => ($e['coordinate_system'] ?? null) === 'helioprojective'),
            function ($carry, $event) {
                $carry[$event['id']] = [
                    'x' => $event['hv_hpc_x'],
                    'y' => $event['hv_hpc_y'],
                    'coordinate_time' => $event['coordinate_time'],
                ];
                return $carry;
            },
            []
        );


        $helioprojectiveRotatedCoords = [];
        if (!empty($helioprojectiveCoords)) {
            try {
                $helioprojectiveRotatedCoords = $this->coordinator->helioprojectiveToHelioprojectiveBatch($helioprojectiveCoords, $targetTimestamp);
            } catch (\Helioviewer\EventsApi\Coordinator\CoordinatorException $e) {
                $this->logger->warning("API v1: HttpCoordinator failed process helioprojectiveCoords : " . $e->getMessage());
                try {
                    $helioprojectiveRotatedCoords = $this->backupCoordinator->helioprojectiveToHelioprojectiveBatch($helioprojectiveCoords, $targetTimestamp);
                } catch (\Helioviewer\EventsApi\Coordinator\CoordinatorException $backupError) {
                    $this->logger->error("API v1: Both coordinators failed process helioprojectiveCoords  " . $backupError->getMessage());
                }
            }
        }

        // Merge both coordinate arrays (preserves event IDs as keys)
        $rotatedCoordinates = $stonyhurstRotatedCoordinates + $helioprojectiveRotatedCoords;

        // Apply rotated coordinates to events
        $result = [];
        foreach ($eventsArray as $event) {
            $eventId = $event['id'];
            if (isset($rotatedCoordinates[$eventId])) {
                $rotated = $rotatedCoordinates[$eventId];
                $event['hv_hpc_x'] = $rotated['hpc_x'];
                $event['hv_hpc_y'] = $rotated['hpc_y'];
            }
            $result[] = $event;
        }

        return $result;
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

        // Rotate all events to target observation time
        $eventsWithRotatedCoords = $this->rotateAllEvents($eventsArray, $parsedTimestamp);

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
