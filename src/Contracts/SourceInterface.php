<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Contracts;

use Helioviewer\EventsApi\Models\Event;

/**
 * Interface for solar event data sources
 */
interface SourceInterface
{
    /**
     * Fetch events from the source between start and end times
     * 
     * @param int $start Start timestamp for event query
     * @param int $end End timestamp for event query
     * @return Event[] Array of Event objects
     */
    public function fetch(int $start, int $end): array;
}
