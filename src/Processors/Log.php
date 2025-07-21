<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Processors;

use Helioviewer\EventsApi\Contracts\EventProcessorInterface;
use Helioviewer\EventsApi\Models\Event;

/**
 * Logging implementation of EventProcessorInterface
 * 
 * Logs events to PHP error log instead of saving to database.
 */
class Log implements EventProcessorInterface
{
    /**
     * Log events to console and error log
     * 
     * @param Event[] $events Array of Event objects to log
     * @return void
     */
    public function process(array $events): void
    {
        echo "Log Processor: Processing " . count($events) . " events\n";
        
        foreach ($events as $event) {
            echo "Event: " . $event->remote_id . " | " . $event->label . " | Duration: " . $event->duration . "s\n";
            error_log("Event logged: " . json_encode($event->getAttributes()));
        }
        
        echo "Log Processor: All events processed successfully\n\n";
    }
}