<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator\HPC\Strategies;

use Helioviewer\EventsApi\Events\Event;
use Illuminate\Database\Eloquent\Collection;
use Psr\Log\LoggerInterface;

/**
 * Events already stored in helioprojective arcsec (RHESSI, and the HEK records
 * whose producer submitted a projected position): straight copy, no coordinator
 * calls.
 *
 * No coordinator reply means no per-vertex `visible` flag. That is not a gap —
 * there is nothing to report. A helioprojective coordinate is a projection, and
 * the projection is where the side-of-the-Sun information was lost: two points
 * on opposite sides of the Sun land on the same x/y. Nothing downstream can
 * recover it, which is measurable rather than theoretical — of the 1,042,918
 * HEK events whose producer submitted one, zero classify as far side once
 * converted back to degrees.
 *
 * Events that arrived in a heliographic system keep it and are claimed by
 * StonyhurstStrategy or CarringtonStrategy instead, which do get the flag.
 *
 * @package Helioviewer\EventsApi\Coordinator\HPC\Strategies
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class HelioprojectiveStrategy implements HPCStrategyInterface
{
    private ?LoggerInterface $logger;

    /**
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Claims helioprojective events, and rows predating the
     * coordinate_system column — those were written when every event was HPC,
     * and CoordinateRotator already serves them unrotated.
     *
     * @param Event $event Event to test
     * @return bool
     */
    public function applies(Event $event): bool
    {
        return $event->coordinate_system === 'helioprojective'
            || $event->coordinate_system === null;
    }

    /**
     * @param Collection $events Helioprojective events
     * @return void
     */
    public function apply(Collection $events): void
    {
        // Already arcsec, so the snapshot is a straight copy — no coordinator call.
        foreach ($events as $event) {
            $event->x_hpc = $event->hv_hpc_x;
            $event->y_hpc = $event->hv_hpc_y;
            $event->footprint_hpc = is_array($event->footprint) ? $event->footprint : [];
        }

        if ($this->logger) {
            $this->logger->debug('HPCResolver | helioprojective | copied ' . $events->count() . ' events');
        }
    }
}
