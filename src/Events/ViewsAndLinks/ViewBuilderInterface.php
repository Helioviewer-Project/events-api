<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\ViewsAndLinks;

/**
 * Builds an event's views and source link from its raw record, at request time.
 *
 * @package Helioviewer\EventsApi\Events\ViewsAndLinks
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
interface ViewBuilderInterface
{
    /**
     * @param array $raw   The raw source record (sources/<uuid>.json)
     * @param array $event The event row; at least `id` and `path`
     * @return array List of tabs: ['name' => ..., 'content' => [...], 'tabgroup'?]
     */
    public function views(array $raw, array $event): array;

    /**
     * @return array ['url' => ..., 'text' => ...], or [] for the default event link
     */
    public function link(array $raw, array $event): array;
}
