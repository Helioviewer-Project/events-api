<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\ViewsAndLinks;

/**
 * RHESSI flares: the record itself is the view, linked to its analysis page.
 *
 * @package Helioviewer\EventsApi\Events\ViewsAndLinks
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class RhessiViews implements ViewBuilderInterface
{
    public function views(array $raw, array $event): array
    {
        return [['name' => 'Main', 'content' => $raw]];
    }

    public function link(array $raw, array $event): array
    {
        return empty($raw['link']) ? [] : ['url' => $raw['link'], 'text' => 'Full analysis'];
    }
}
