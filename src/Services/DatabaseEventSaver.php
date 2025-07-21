<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Services;

use Helioviewer\EventsApi\Contracts\EventSaverInterface;
use Helioviewer\EventsApi\Models\Event;
use Illuminate\Database\Capsule\Manager as Capsule;
use Exception;

/**
 * Database implementation of EventSaverInterface
 * 
 * Saves events to the database using Eloquent/Capsule.
 */
class DatabaseEventSaver implements EventSaverInterface
{
    /**
     * Save events to the database with create/update logic
     * 
     * @param Event[] $events Array of Event objects to save
     * @return void
     */
    public function save(array $events): void
    {
        foreach ($events as $event) {
            try {
                // Try to find existing event by remote_id and source_id
                $existingEvent = Event::where('remote_id', $event->remote_id)
                                     ->where('source_id', $event->source_id)
                                     ->first();
                
                if ($existingEvent) {
                    // Update existing event
                    $existingEvent->fill($event->getAttributes());
                    $existingEvent->save();
                    echo "Updated event: " . $event->remote_id . "\n";
                } else {
                    // Create new event
                    $event->save();
                    echo "Created event: " . $event->remote_id . "\n";
                }
                
            } catch (Exception $e) {
                error_log("Failed to save event " . $event->remote_id . ": " . $e->getMessage());
            }
        }
    }
}