<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Contracts;

use Helioviewer\EventsApi\Models\Event;

/**
 * Interface for saving collected event data
 * 
 * Defines how collected events should be saved - whether to database,
 * file system, or other storage strategies.
 */
interface EventSaverInterface
{
    /**
     * Save an array of collected events
     * 
     * @param Event[] $events Array of Event objects to save
     * @return void
     */
    public function save(array $events): void;
}