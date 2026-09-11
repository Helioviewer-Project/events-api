<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Helioviewer\EventsApi\Coordinator\HPC\HPCResolver;
use Helioviewer\EventsApi\Events\Event;
use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rotates event coordinates to a target observation time.
 *
 * Centers are rotated in the coordinate system the source gave us: carrington
 * and stonyhurst events send their degrees to the matching route, so the
 * coordinator knows which side of the Sun the point is on and returns both the
 * position and the `visible` flag for it. Helioprojective events (HEK, RHESSI)
 * send the stored arcsec snapshot, which is what /hpc expects.
 *
 * footprint_hpc is rigidly shifted by the center's delta; its per-vertex
 * visible flags are carried through untouched. Events missing their snapshot
 * are resolved in-memory first via HPCResolver.
 *
 * Failover and Sentry reporting live in the injected coordinator
 * (FailoverCoordinator); a failed batch leaves events serving their stored
 * values, as before.
 *
 * @package    Helioviewer\EventsApi\Coordinator
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class CoordinateRotator
{
    private CoordinatorInterface $coordinator;
    private HPCResolver $hpcResolver;
    private LoggerInterface $logger;
    private ?CacheInterface $cache;

    /**
     * @param CoordinatorInterface $coordinator Coordinator (failover-wrapped)
     * @param HPCResolver $hpcResolver Fills missing native-HPC snapshots in-memory
     * @param LoggerInterface $logger Logger
     * @param CacheInterface|null $cache Optional cache for rotation results
     */
    public function __construct(
        CoordinatorInterface $coordinator,
        HPCResolver $hpcResolver,
        LoggerInterface $logger,
        ?CacheInterface $cache = null
    ) {
        $this->coordinator = $coordinator;
        $this->hpcResolver = $hpcResolver;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * Rotate all events to a target observation time.
     *
     * Sets hv_hpc_x/hv_hpc_y to the rotated center and replaces footprint
     * with footprint_hpc shifted by the center delta (a footprint is a LIST
     * of polygons [[{x,y},…],…]). With $withFootprints false (batch/movie
     * endpoints read centers only) footprints are left untouched.
     *
     * Also sets `visible` on every event: whether its center faces the observer
     * at the target time, read from the same /hpc result. Far-side footprint
     * vertices keep their snapshot-time visible=false key through the shift.
     *
     * @param Collection $events Eloquent Collection of Event models
     * @param int $targetTimestamp Target observation time (Unix timestamp)
     * @param bool $withFootprints Shift footprints by the center delta (default true)
     * @return Collection Events with rotated coordinates
     */
    public function rotate(Collection $events, int $targetTimestamp, bool $withFootprints = true): Collection
    {
        if ($events->isEmpty()) {
            return $events;
        }

        // Transitional: resolve rows without a stored snapshot, in-memory only.
        $unresolved = $events->filter(fn($event) => $event->footprint_hpc === null || $event->x_hpc === null);
        if ($unresolved->isNotEmpty()) {
            $this->logger->info("CoordinateRotator | Resolving {$unresolved->count()} events without native-HPC snapshot");
            $this->hpcResolver->resolve($unresolved);
        }

        $rotatedCoordinates = $this->rotateCenters(
            $events->filter(fn($event) => $event->x_hpc !== null),
            $targetTimestamp
        );

        return $events->map(function ($event) use ($rotatedCoordinates, $withFootprints) {
            // Fail-open: an unresolved event, a failed batch or a coordinator
            // without the flag all serve visible.
            $event->visible = true;

            if (!isset($rotatedCoordinates[$event->id])) {
                return $event; // unresolved or failed batch: serve stored values
            }

            $rotated = $rotatedCoordinates[$event->id];
            $dx = $rotated['hpc_x'] - $event->x_hpc;
            $dy = $rotated['hpc_y'] - $event->y_hpc;

            $event->hv_hpc_x = $rotated['hpc_x'];
            $event->hv_hpc_y = $rotated['hpc_y'];
            $event->visible  = $rotated['visible'] ?? true;

            if (!$withFootprints) {
                return $event; // batch endpoints read centers only
            }

            $footprint = is_array($event->footprint_hpc) ? $event->footprint_hpc : [];
            $shifted = [];
            foreach ($footprint as $polygon) {
                $shiftedPolygon = [];
                foreach ($polygon as $point) {
                    $shiftedPoint = [
                        'x' => (float) $point['x'] + $dx,
                        'y' => (float) $point['y'] + $dy,
                    ];
                    // Snapshot-time flag, moved but not recomputed.
                    if (isset($point['visible']) && $point['visible'] === false) {
                        $shiftedPoint['visible'] = false;
                    }
                    $shiftedPolygon[] = $shiftedPoint;
                }
                $shifted[] = $shiftedPolygon;
            }
            $event->footprint = $shifted;

            return $event;
        });
    }

    /**
     * Rotate centers to the target time, one batch per coordinate system.
     *
     * @param Collection $events Events with a resolved snapshot
     * @param int $targetTimestamp Target observation time
     * @return array Rotated coordinates keyed by event ID
     */
    private function rotateCenters(Collection $events, int $targetTimestamp): array
    {
        if ($events->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($events->groupBy(fn($e) => $e->coordinateSystem()) as $system => $eventGroup) {
            $result += $this->rotateSameCoordinateGroup((string) $system, $eventGroup, $targetTimestamp);
        }

        return $result;
    }

    /**
     * Rotate one group of events sharing a coordinate system, through the route
     * built for that system. Cached 24h.
     *
     * Neither match has a default: Event::coordinateSystem() can only return
     * these three names, so a fourth added there without updating here throws
     * immediately instead of routing a new system silently through /hpc.
     *
     * @param string $system carrington, stonyhurst, or helioprojective
     * @param Collection $events Events in that system
     * @param int $target Target observation time
     * @return array Rotated coordinates keyed by event ID
     */
    private function rotateSameCoordinateGroup(string $system, Collection $events, int $target): array
    {
        $coordinates = [];
        foreach ($events as $event) {
            $coordinates[$event->id] = match ($system) {

                // WSA: hv_hpc_x/y hold Carrington longitude and latitude in degrees.
                'carrington' => [
                    'lat'             => (float) $event->hv_hpc_y,
                    'lon'             => (float) $event->hv_hpc_x,
                    'coordinate_time' => $event->coordinate_time,
                ],

                // CCMC: hv_hpc_x/y hold Stonyhurst longitude and latitude in degrees.
                'stonyhurst' => [
                    'lat'             => (float) $event->hv_hpc_y,
                    'lon'             => (float) $event->hv_hpc_x,
                    'coordinate_time' => $event->coordinate_time,
                ],

                // HEK, RHESSI: already arcsec, so the snapshot is the right input
                // and /hpc's Earth-observed assumption about it is correct.
                'helioprojective' => [
                    'x'               => $event->x_hpc,
                    'y'               => $event->y_hpc,
                    'coordinate_time' => $event->coordinate_time,
                ],
            };
        }

        $coordCount = count($coordinates);
        $this->logger->debug("CoordinateRotator | Rotating {$coordCount} {$system} centers");

        // The system belongs in the key, or two groups of the same size collide.
        $cacheKey = "coordinator:rot:{$system}:" . md5(serialize($coordinates) . $target);
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->logger->info("CoordinateRotator | Cache HIT | {$coordCount} {$system} coordinates");
                return $cached;
            }
        }

        try {
            $result = match ($system) {
                'carrington'      => $this->coordinator->carringtonToHelioprojectiveBatch($coordinates, $target),
                'stonyhurst'      => $this->coordinator->stonyhurstToHelioprojectiveBatch($coordinates, $target),
                'helioprojective' => $this->coordinator->helioprojectiveToHelioprojectiveBatch($coordinates, $target),
            };
        } catch (CoordinatorException $e) {
            // Only this system falls back; the other groups still rotate.
            $this->logger->error("CoordinateRotator | {$system} rotation failed for {$coordCount} coordinates | " . $e->getMessage());
            return [];
        }

        if ($this->cache !== null && !empty($result)) {
            $this->cache->set($cacheKey, $result, 86400);
        }

        return $result;
    }

}
