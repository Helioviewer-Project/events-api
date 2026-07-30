<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Helioviewer\EventsApi\Coordinator\HPC\HPCResolver;
use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rotates event coordinates to a target observation time.
 *
 * Single path for every coordinate system: the stored native-HPC snapshot
 * (x_hpc, y_hpc at the event's own coordinate_time) is rotated with one
 * hpc-to-hpc batch call, and footprint_hpc is rigidly shifted by the center's
 * delta. Events missing their snapshot (backfill not run / earlier resolve
 * failure) are resolved in-memory first via HPCResolver — that branch goes
 * quiet once backfill coverage is complete.
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
            if (!isset($rotatedCoordinates[$event->id])) {
                return $event; // unresolved or failed batch: serve stored values
            }

            $rotated = $rotatedCoordinates[$event->id];
            $dx = $rotated['hpc_x'] - $event->x_hpc;
            $dy = $rotated['hpc_y'] - $event->y_hpc;

            $event->hv_hpc_x = $rotated['hpc_x'];
            $event->hv_hpc_y = $rotated['hpc_y'];

            if (!$withFootprints) {
                return $event; // batch endpoints read centers only
            }

            $footprint = is_array($event->footprint_hpc) ? $event->footprint_hpc : [];
            $shifted = [];
            foreach ($footprint as $polygon) {
                $shiftedPolygon = [];
                foreach ($polygon as $point) {
                    $shiftedPolygon[] = [
                        'x' => (float) $point['x'] + $dx,
                        'y' => (float) $point['y'] + $dy,
                    ];
                }
                $shifted[] = $shiftedPolygon;
            }
            $event->footprint = $shifted;

            return $event;
        });
    }

    /**
     * Rotate native-HPC centers to the target time, cached 24h.
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

        $coordinates = [];
        foreach ($events as $event) {
            $coordinates[$event->id] = [
                'x' => $event->x_hpc,
                'y' => $event->y_hpc,
                'coordinate_time' => $event->coordinate_time,
            ];
        }

        $coordCount = count($coordinates);
        $this->logger->debug("CoordinateRotator | Rotating {$coordCount} native-HPC centers");

        if ($this->cache !== null) {
            $cacheKey = 'coordinator:hpc:' . md5(serialize($coordinates) . $targetTimestamp);
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->logger->info("CoordinateRotator | Cache HIT | {$coordCount} coordinates");
                return $cached;
            }
        }

        try {
            $result = $this->coordinator->helioprojectiveToHelioprojectiveBatch($coordinates, $targetTimestamp);
        } catch (CoordinatorException $e) {
            $this->logger->error("CoordinateRotator | Rotation failed for {$coordCount} coordinates | " . $e->getMessage());
            return [];
        }

        if ($this->cache !== null && !empty($result) && isset($cacheKey)) {
            $this->cache->set($cacheKey, $result, 86400);
        }

        return $result;
    }
}
