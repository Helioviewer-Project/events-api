<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Services;

use Helioviewer\EventsApi\Contracts\EventSaverInterface;
use Helioviewer\EventsApi\Models\Event;

/**
 * Logging implementation of EventSaverInterface
 * 
 * Logs events to PHP error log instead of saving to database.
 */
class LogEventSaver implements EventSaverInterface
{
    /**
     * Log events to console and error log
     * 
     * @param Event[] $events Array of Event objects to log
     * @return void
     */
    public function save(array $events): void
    {
        echo "LogEventSaver: Processing " . count($events) . " events\n";
        
        foreach ($events as $event) {
            echo "Event: " . $event->remote_id . " | " . $event->label . " | Duration: " . $event->duration . "s\n";
            error_log("Event logged: " . json_encode($event->getAttributes()));
        }
        
        echo "LogEventSaver: All events logged successfully\n\n";
    }
}