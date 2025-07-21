<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Sources;

use Helioviewer\EventsApi\Models\Event;
use HelioviewerEventInterface\Translator\IgnoreCme as EventInterfaceIgnoreDonkiCmeException;
use HelioviewerEventInterface\Translator\DonkiCme as EventInterfaceDonkiCme;


/**
 * DONKI CME (Coronal Mass Ejection) event source
 * 
 * Fetches CME event data from NASA's DONKI (Database of Notifications, 
 * Knowledge, Information) API and converts it to Event model instances.
 */
class DonkiCme extends HttpSource
{
    /**
     * Build the HTTP request for fetching CME events from DONKI API
     * 
     * @param int $start Start timestamp for event query
     * @param int $end End timestamp for event query
     * @return \Psr\Http\Message\RequestInterface The HTTP request object
     */
    protected function request(int $start, int $end): \Psr\Http\Message\RequestInterface
    {
        $startDate = date('Y-m-d', $start);
        $endDate = date('Y-m-d', $end);
        
        $baseUrl = "https://kauai.ccmc.gsfc.nasa.gov/DONKI/WS/get/CME";
        $queryParams = [
            'startDate' => $startDate,
            'endDate' => $endDate,
        ];
        
        $uri = $baseUrl . '?' . http_build_query($queryParams);
        
        return new \GuzzleHttp\Psr7\Request('GET', $uri);
    }

    /**
     * Process the HTTP response and return processed CME events
     * 
     * @param \Psr\Http\Message\ResponseInterface $response HTTP response from the DONKI CME API
     * @return Event[] Array of Event model instances
     */
    protected function processResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        // Check content type
        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'application/json')) {
            error_log("Unexpected content type from CME API: " . $contentType);
            return [];
        }
        
        $responseBody = $response->getBody()->getContents();
        
        // Check if response is valid JSON
        $rawEvents = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Invalid JSON response from CME API: " . json_last_error_msg());
            return [];
        }

        echo "Found " . count($rawEvents) . " raw events:\n";
        foreach ($rawEvents as $index => $rawEvent) {
            echo "  " . ($index + 1) . ". Activity ID: " . ($rawEvent['activityID'] ?? 'N/A') . "\n";
        }
        echo "\n";
        
        // Process raw events and convert to normalized format
        $events = [];
        
        foreach ($rawEvents as $rawEvent) {
            try {
                $translatedEvent = EventInterfaceDonkiCme::buildTranslatedCME($rawEvent);
                
                // Map translated event to database fields
                $eventData = [
                    'remote_id' => $translatedEvent['id'],
                    'response_hash' => md5(json_encode($rawEvent)),
                    'source_id' => Source::CCMC,
                    'path' => $this->getPath(),
                    'start' => strtotime($translatedEvent['start']),
                    'peak' => strtotime($translatedEvent['start']), // Use start time as peak for CME
                    'end' => strtotime($translatedEvent['end']),
                    'hv_hpc_x' => (float) $translatedEvent['hv_hpc_x'],
                    'hv_hpc_y' => (float) $translatedEvent['hv_hpc_y'],
                    'label' => $translatedEvent['label'],
                    'translator' => 'DonkiCme',
                    'legacy_version' => $translatedEvent['version'] ?? null,
                    'legacy_type' => $translatedEvent['type'] ?? null,
                    'legacy_pin' => $translatedEvent['type'] ?? 'CE',
                ];

                // Create Event model instance (without saving to database)
                $event = new Event($eventData);
                $events[] = $event;
                
                echo "Processed event: " . $event->remote_id . " (Duration: " . $event->duration . "s)\n";
                
            } catch (EventInterfaceIgnoreDonkiCmeException $e) {
                error_log("Ignoring CME event: " . $e->getMessage());
            }
        }
        
        return $events;
    }
}
