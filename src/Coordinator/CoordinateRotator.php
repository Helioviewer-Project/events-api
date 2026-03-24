<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Coordinate Rotator
 *
 * Rotates event coordinates to a target observation time using primary
 * and backup coordinators with automatic failover.
 *
 * @package    Helioviewer\EventsApi\Coordinator
 * @since      1.0.0
 */
class CoordinateRotator
{
    private CoordinatorInterface $coordinator;
    private CoordinatorInterface $backupCoordinator;
    private LoggerInterface $logger;
    private ?CacheInterface $cache;
    private bool $primaryFailed = false;

    /**
     * Constructor
     *
     * @param CoordinatorInterface $coordinator Primary coordinator for transformations
     * @param CoordinatorInterface $backupCoordinator Backup coordinator for failover
     * @param LoggerInterface $logger Logger for debug/error messages
     * @param CacheInterface|null $cache Optional cache for rotation results
     */
    public function __construct(
        CoordinatorInterface $coordinator,
        CoordinatorInterface $backupCoordinator,
        LoggerInterface $logger,
        ?CacheInterface $cache = null
    ) {
        $this->coordinator = $coordinator;
        $this->backupCoordinator = $backupCoordinator;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * Rotate all events to a target observation time
     *
     * Transforms event coordinates from their original observation times
     * to a target observation time, handling both Stonyhurst (HGS) and
     * Helioprojective (HPC) coordinate systems with automatic failover.
     *
     * @param Collection $events Eloquent Collection of Event models
     * @param int $targetTimestamp Target observation time (Unix timestamp)
     * @return Collection Events collection with updated hv_hpc_x and hv_hpc_y coordinates
     */
    public function rotate(Collection $events, int $targetTimestamp): Collection
    {
        if ($events->isEmpty()) {
            return $events;
        }

        $grouped = $events->groupBy('coordinate_system');

        $stonyhurstRotated = $this->rotateStonyhurstCoordinates(
            $grouped->get('stonyhurst', new Collection()),
            $targetTimestamp
        );

        $helioprojectiveRotated = $this->rotateHelioprojectiveCoordinates(
            $grouped->get('helioprojective', new Collection()),
            $targetTimestamp
        );

        $rotatedCoordinates = $stonyhurstRotated + $helioprojectiveRotated;

        return $events->map(function ($event) use ($rotatedCoordinates) {
            if (isset($rotatedCoordinates[$event->id])) {
                $rotated = $rotatedCoordinates[$event->id];
                $dx = $rotated['hpc_x'] - $event->hv_hpc_x;
                $dy = $rotated['hpc_y'] - $event->hv_hpc_y;

                $event->hv_hpc_x = $rotated['hpc_x'];
                $event->hv_hpc_y = $rotated['hpc_y'];

                // Shift footprint points by the center's rotation offset
                if (!empty($event->footprint) && is_array($event->footprint)) {
                    $fpCount = count($event->footprint);
                    $this->logger->debug("CoordinateRotator | Event {$event->id} | Shifting {$fpCount} footprint points | dx: {$dx} | dy: {$dy}");
                    $rotatedFootprint = [];
                    foreach ($event->footprint as $point) {
                        $rotatedFootprint[] = [
                            'x' => (float) $point['x'] + $dx,
                            'y' => (float) $point['y'] + $dy,
                        ];
                    }
                    $event->footprint = $rotatedFootprint;
                }
            }

            return $event;
        });
    }

    /**
     * Filter and transform Stonyhurst coordinates
     *
     * @param Collection $events Collection of stonyhurst events
     * @param int $targetTimestamp Target observation time
     * @return array Rotated coordinates keyed by event ID
     */
    private function rotateStonyhurstCoordinates(Collection $events, int $targetTimestamp): array
    {
        $stonyhurstCoords = $events
            ->filter(fn($event) => $event->hv_hpc_y >= -90 && $event->hv_hpc_y <= 90)
            ->keyBy('id')
            ->map(fn($event) => [
                'lat' => $event->hv_hpc_y,
                'lon' => $event->hv_hpc_x,
                'coordinate_time' => $event->coordinate_time,
            ])
            ->toArray();

        if (empty($stonyhurstCoords)) {
            return [];
        }

        $coordCount = count($stonyhurstCoords);
        $this->logger->debug("CoordinateRotator | Rotating {$coordCount} Stonyhurst coordinates");

        if ($this->cache !== null) {
            $cacheKey = 'coordinator:stonyhurst:' . md5(serialize($stonyhurstCoords) . $targetTimestamp);
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->logger->info("CoordinateRotator | Stonyhurst | Cache HIT | {$coordCount} coordinates");
                return $cached;
            }
        }

        $result = $this->transformWithFallback(
            fn() => $this->coordinator->stonyhurstToHelioprojectiveBatch($stonyhurstCoords, $targetTimestamp),
            fn() => $this->backupCoordinator->stonyhurstToHelioprojectiveBatch($stonyhurstCoords, $targetTimestamp),
            'Stonyhurst'
        );

        if ($this->cache !== null && !empty($result) && isset($cacheKey)) {
            $this->cache->set($cacheKey, $result, 86400);
        }

        return $result;
    }

    /**
     * Filter and transform Helioprojective center coordinates
     *
     * Only rotates event center points. Footprint polygon points are shifted
     * by the center's rotation offset in rotate() to avoid expensive batch requests.
     *
     * @param Collection $events Collection of helioprojective events
     * @param int $targetTimestamp Target observation time
     * @return array Rotated coordinates keyed by event ID
     */
    private function rotateHelioprojectiveCoordinates(Collection $events, int $targetTimestamp): array
    {
        if ($events->isEmpty()) {
            return [];
        }

        $allCoords = [];

        foreach ($events as $event) {
            $allCoords[$event->id] = [
                'x' => $event->hv_hpc_x,
                'y' => $event->hv_hpc_y,
                'coordinate_time' => $event->coordinate_time,
            ];
        }

        $coordCount = count($allCoords);
        $this->logger->debug("CoordinateRotator | Rotating {$coordCount} Helioprojective center coordinates");

        if ($this->cache !== null) {
            $cacheKey = 'coordinator:hpc:' . md5(serialize($allCoords) . $targetTimestamp);
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $this->logger->info("CoordinateRotator | Helioprojective | Cache HIT | {$coordCount} coordinates");
                return $cached;
            }
        }

        $result = $this->transformWithFallback(
            fn() => $this->coordinator->helioprojectiveToHelioprojectiveBatch($allCoords, $targetTimestamp),
            fn() => $this->backupCoordinator->helioprojectiveToHelioprojectiveBatch($allCoords, $targetTimestamp),
            'Helioprojective'
        );

        if ($this->cache !== null && !empty($result) && isset($cacheKey)) {
            $this->cache->set($cacheKey, $result, 86400);
        }

        return $result;
    }

    /**
     * Try primary coordinator, fall back to backup on failure
     *
     * @param callable $primary Primary transformation callable
     * @param callable $backup Backup transformation callable
     * @param string $system Coordinate system name for logging
     * @return array Rotated coordinates keyed by event ID
     */
    private function transformWithFallback(callable $primary, callable $backup, string $system): array
    {
        // Skip primary if it had a connection failure during this request
        if (!$this->primaryFailed) {
            try {
                return $primary();
            } catch (CoordinatorConnectionException $e) {
                // Server unreachable (timeout, refused, DNS) — skip primary for remaining calls
                $this->primaryFailed = true;
                $this->logger->warning("CoordinateRotator | {$system} | Primary unreachable: " . $e->getMessage() . " | Skipping primary for remaining calls, falling back to backup");
            } catch (CoordinatorException $e) {
                // Server reachable but returned error (400, 500, bad format) — try primary again next time
                $this->logger->warning("CoordinateRotator | {$system} | Primary returned error: " . $e->getMessage() . " | Falling back to backup for this call");
            }
        }

        try {
            return $backup();
        } catch (CoordinatorException $backupError) {
            $this->logger->error("CoordinateRotator | {$system} | Backup LOCAL http coordinator also failed: " . $backupError->getMessage() . " | No coordinates rotated");
            return [];
        }
    }
}
