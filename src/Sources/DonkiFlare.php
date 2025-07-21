<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Sources;

use Helioviewer\EventsApi\Models\Event;
use HelioviewerEventInterface\Translator\DonkiFlare as EventInterfaceDonkiFlare;
use HelioviewerEventInterface\Util\LocationParser;

/**
 * DONKI Solar Flare event source
 * 
 * Fetches solar flare event data from NASA's DONKI (Database of Notifications, 
 * Knowledge, Information) API and converts it to Event model instances.
 */
class DonkiFlare extends HttpSource
{
    /**
     * Build the HTTP request for fetching solar flare events from DONKI API
     * 
     * @param int $start Start timestamp for event query
     * @param int $end End timestamp for event query
     * @return \Psr\Http\Message\RequestInterface The HTTP request object
     */
    protected function request(int $start, int $end): \Psr\Http\Message\RequestInterface
    {
        $startDate = date('Y-m-d', $start);
        $endDate = date('Y-m-d', $end);
        
        $baseUrl = "https://kauai.ccmc.gsfc.nasa.gov/DONKI/WS/get/FLR";
        $queryParams = [
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
        
        $uri = $baseUrl . '?' . http_build_query($queryParams);
        
        return new \GuzzleHttp\Psr7\Request('GET', $uri);
    }

    /**
     * Process the HTTP response and return processed solar flare events
     * 
     * @param \Psr\Http\Message\ResponseInterface $response HTTP response from the DONKI Solar Flare API
     * @return Event[] Array of Event model instances
     */
    protected function processResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        // Check content type
        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'application/json')) {
            error_log("Unexpected content type from Solar Flare API: " . $contentType);
            return [];
        }
        
        $responseBody = $response->getBody()->getContents();
        
        // Check if response is valid JSON
        $rawEvents = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Invalid JSON response from Solar Flare API: " . json_last_error_msg());
            return [];
        }

        echo "Found " . count($rawEvents) . " raw events:\n";
        foreach ($rawEvents as $index => $rawEvent) {
            echo "  " . ($index + 1) . ". Activity ID: " . ($rawEvent['activityID'] ?? 'N/A') . "\n";
        }
        echo "\n";
        
        // Process raw events and convert to Event models
        $events = [];
        
        foreach ($rawEvents as $rawEvent) {
            try {
                // Use event interface to convert raw flare to event info
                $translatedEvent = EventInterfaceDonkiFlare::makeEventFromRawFlare($rawEvent);
                
                // Extract Stonyhurst coordinates from source location
                $latitude = 0.0;
                $longitude = 0.0;
                
                if (isset($rawEvent['sourceLocation']) && !empty($rawEvent['sourceLocation'])) {
                    try {
                        $location = LocationParser::ParseText($rawEvent['sourceLocation']);
                        $latitude = (float) $location[0];  // Stonyhurst latitude
                        $longitude = (float) $location[1]; // Stonyhurst longitude
                    } catch (\Exception $e) {
                        error_log("Failed to parse flare location '{$rawEvent['sourceLocation']}': " . $e->getMessage());
                    }
                }
                
                // Map translated event to database fields
                $eventData = [
                    'remote_id' => $translatedEvent['id'],
                    'response_hash' => md5(json_encode($rawEvent)),
                    'source_id' => Source::CCMC,
                    'path' => $this->getPath(),
                    'start' => strtotime($translatedEvent['start']),
                    'peak' => $translatedEvent['peak'] instanceof \DateTime ? $translatedEvent['peak']->getTimestamp() : strtotime($translatedEvent['peak']),
                    'end' => strtotime($translatedEvent['end']),
                    'hv_hpc_x' => $latitude,   // Store Stonyhurst latitude
                    'hv_hpc_y' => $longitude,  // Store Stonyhurst longitude
                    'label' => $translatedEvent['label'],
                    'translator' => 'DonkiFlare',
                    'legacy_version' => $translatedEvent['version'] ?? null,
                    'legacy_type' => $translatedEvent['type'] ?? null,
                    'legacy_pin' => $translatedEvent['type'] ?? 'FL',
                ];

                // Create Event model instance (without saving to database)
                $event = new Event($eventData);
                $events[] = $event;
                
                echo "Processed solar flare: " . $event->remote_id . " (Duration: " . $event->duration . "s)\n";
                
            } catch (\Exception $e) {
                error_log("Error processing solar flare event: " . $e->getMessage());
            }
        }
        
        return $events;
    }
}
