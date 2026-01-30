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
                $event->hv_hpc_x = $rotated['hpc_x'];
                $event->hv_hpc_y = $rotated['hpc_y'];
            }

            // Reassemble rotated footprint from composite keys
            if (!empty($event->footprint) && is_array($event->footprint)) {
                $rotatedFootprint = [];
                foreach ($event->footprint as $index => $point) {
                    $fpKey = "{$event->id}:fp:{$index}";
                    if (isset($rotatedCoordinates[$fpKey])) {
                        $rotatedFootprint[] = [
                            'x' => $rotatedCoordinates[$fpKey]['hpc_x'],
                            'y' => $rotatedCoordinates[$fpKey]['hpc_y'],
                        ];
                    } else {
                        $rotatedFootprint[] = $point;
                    }
                }
                $event->footprint = $rotatedFootprint;
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

        $this->logger->debug("CoordinateRotator | Rotating " . count($stonyhurstCoords) . " Stonyhurst coordinates");

        return $this->transformWithFallback(
            fn() => $this->coordinator->stonyhurstToHelioprojectiveBatch($stonyhurstCoords, $targetTimestamp),
            fn() => $this->backupCoordinator->stonyhurstToHelioprojectiveBatch($stonyhurstCoords, $targetTimestamp),
            'Stonyhurst'
        );
    }

    /**
     * Filter and transform Helioprojective coordinates including footprints
     *
     * Builds a single batch of all HPC coordinates: event center points
     * and individual footprint polygon points. Footprint points use composite
     * keys ({eventId}:fp:{index}) so they can be reassembled after rotation.
     *
     * @param Collection $events Collection of helioprojective events
     * @param int $targetTimestamp Target observation time
     * @return array Rotated coordinates keyed by event ID or composite footprint key
     */
    private function rotateHelioprojectiveCoordinates(Collection $events, int $targetTimestamp): array
    {
        if ($events->isEmpty()) {
            return [];
        }

        $allCoords = [];

        foreach ($events as $event) {
            // Event center point
            $allCoords[$event->id] = [
                'x' => $event->hv_hpc_x,
                'y' => $event->hv_hpc_y,
                'coordinate_time' => $event->coordinate_time,
            ];

            // Footprint polygon points
            $footprint = $event->footprint;
            if (!empty($footprint) && is_array($footprint)) {
                foreach ($footprint as $index => $point) {
                    $allCoords["{$event->id}:fp:{$index}"] = [
                        'x' => (float) $point['x'],
                        'y' => (float) $point['y'],
                        'coordinate_time' => $event->coordinate_time,
                    ];
                }
            }
        }

        $this->logger->debug("CoordinateRotator | Rotating " . count($allCoords) . " Helioprojective coordinates (centers + footprints)");

        return $this->transformWithFallback(
            fn() => $this->coordinator->helioprojectiveToHelioprojectiveBatch($allCoords, $targetTimestamp),
            fn() => $this->backupCoordinator->helioprojectiveToHelioprojectiveBatch($allCoords, $targetTimestamp),
            'Helioprojective'
        );
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
        try {
            return $primary();
        } catch (CoordinatorException $e) {
            $this->logger->warning("CoordinateRotator | Primary coordinator failed for {$system}: " . $e->getMessage());
            try {
                return $backup();
            } catch (CoordinatorException $backupError) {
                $this->logger->error("CoordinateRotator | Both coordinators failed for {$system}: " . $backupError->getMessage());
                return [];
            }
        }
    }
}
