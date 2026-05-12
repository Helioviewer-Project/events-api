<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Helioviewer\EventsApi\Utils\TimestampParser;
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
                        $reorderedArray['url'] = "{$apiUrl}/api/v1/events/{$uuid}";
                    } elseif ($key === 'source') {
                        $reorderedArray['source_url'] = "{$apiUrl}/api/v1/events/{$uuid}/source";
                    } else {
                        $reorderedArray[$key] = $value;
                    }
                }

                return $reorderedArray;
            }, $events);

            return $this->json($response, $enhancedEvents);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get recent events: " . $e->getMessage());
            $this->sentry->capture($e);
            return $this->error($response, 'Failed to retrieve recent events');
        }
    }


    /**
     * Get events by observation
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

        $this->logger->debug("API v1: Found " . $events->count() . " events for {$source} at " . date('Y-m-d H:i:s', $parsedTimestamp));

        // Rotate all events to target observation time
        $rotatedEvents = $this->coordinateRotator->rotate($events, $parsedTimestamp);

        // Format events
        $formattedEvents = $rotatedEvents->map(fn($event) => $this->enhanceEvent($event))->values()->toArray();

        return $this->json($response, $formattedEvents);

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

            return $this->json($response, $this->enhanceEvent($event));

        } catch (\Exception $e) {
            $this->logger->error("Failed to get event by UUID: " . $e->getMessage());
            $this->sentry->capture($e);
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
            $this->sentry->capture($e);
            return $this->error($response, 'Failed to retrieve source data');
        }
    }
}
