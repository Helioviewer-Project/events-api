<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Contracts;

use Helioviewer\EventsApi\Models\Event;

/**
 * Interface for event processing implementations
 */
interface EventProcessorInterface
{
    /**
     * Process an array of Event objects
     * 
     * @param Event[] $events Array of Event objects to process
     * @return void
     */
    public function process(array $events): void;
}