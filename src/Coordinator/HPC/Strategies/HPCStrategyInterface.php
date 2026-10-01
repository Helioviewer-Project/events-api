<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator\HPC\Strategies;

use Helioviewer\EventsApi\Events\Event;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fills an event's native-HPC snapshot (x_hpc, y_hpc, footprint_hpc): the
 * event as seen from Earth at its own coordinate_time, in arcsec.
 *
 * HPCResolver partitions a batch by the first strategy whose applies() claims
 * each event, then calls that strategy's apply() once per bucket.
 *
 * @package Helioviewer\EventsApi\Coordinator\HPC\Strategies
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
interface HPCStrategyInterface
{
    /**
     * Whether this strategy handles the event. Cheap, no I/O.
     *
     * @param Event $event Event to test
     * @return bool
     */
    public function applies(Event $event): bool;

    /**
     * Resolve the HPC fields for a batch of claimed events, in-place.
     *
     * Callers persist. Unresolvable events are left untouched (attributes
     * unset) so existing values survive the save and the row stays on the
     * backfill worklist. footprint_hpc is written last — it is the marker.
     *
     * @param Collection $events Events claimed by this strategy
     * @return void
     */
    public function apply(Collection $events): void;
}
