<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Helioviewer\EventsApi\Events\Event;

final class WrapFootprintsInPolygonList extends AbstractMigration
{
    /**
     * Migrate Up.
     *
     * The canonical footprint shape becomes a LIST of polygons ([[{x,y},…],…])
     * for every source, so multi-contour events (WSA coronal-hole maps) and
     * single-polygon events (HEK, WSA footpoints) share one format.
     *
     * Wraps every legacy single-polygon footprint ([{x,y},…]) in []. Flat rows
     * are matched by their JSON text prefix '[{' (Eloquent's json_encode emits
     * no whitespace); nested rows start with '[[', empty rows are '[]' and NULL
     * never matches LIKE — so re-running is a no-op. The Event model's 'array'
     * cast handles decode/encode; timestamps are left untouched.
     */
    public function up(): void
    {
        Event::without('regions')
            ->where('footprint', 'LIKE', '[{%')
            ->chunkById(200, function ($events) {
                foreach ($events as $event) {
                    $event->footprint = [$event->footprint];
                    $event->timestamps = false;
                    $event->save();
                }
            });
    }

    /**
     * Migrate Down.
     *
     * Unwraps single-polygon footprints back to the flat legacy shape.
     * Multi-polygon footprints (WSA coronal-hole maps) cannot be flattened
     * and are left as-is.
     */
    public function down(): void
    {
        Event::without('regions')
            ->where('footprint', 'LIKE', '[[%')
            ->chunkById(200, function ($events) {
                foreach ($events as $event) {
                    $footprint = $event->footprint;
                    if (is_array($footprint) && count($footprint) === 1) {
                        $event->footprint = $footprint[0];
                        $event->timestamps = false;
                        $event->save();
                    }
                }
            });
    }
}
