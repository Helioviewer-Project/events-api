<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI;

use Helioviewer\EventsApi\Events\ViewsAndLinks\ViewBuilderInterface;
use HelioviewerEventInterface\Translator\DonkiFlare;

/**
 * CCMC DONKI flares, through the event-interface translator (no network).
 *
 * @package Helioviewer\EventsApi\Events\ViewsAndLinks\DONKI
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class FlareViews implements ViewBuilderInterface
{
    public function views(array $raw, array $event): array
    {
        return (new DonkiFlare($raw))->views();
    }

    public function link(array $raw, array $event): array
    {
        return empty($raw['link']) ? [] : ['url' => $raw['link'], 'text' => 'View on DONKI site'];
    }
}
