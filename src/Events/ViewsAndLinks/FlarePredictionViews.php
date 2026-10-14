<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\ViewsAndLinks;

/**
 * CCMC Flare Scoreboard predictions: the record itself is the view.
 *
 * @package Helioviewer\EventsApi\Events\ViewsAndLinks
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class FlarePredictionViews implements ViewBuilderInterface
{
    public function views(array $raw, array $event): array
    {
        return [['name' => 'Flare Prediction', 'content' => $raw]];
    }

    public function link(array $raw, array $event): array
    {
        return [];
    }
}
