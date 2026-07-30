<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator\HPC\Strategies;

use Helioviewer\EventsApi\Coordinator\CoordinatorException;
use Helioviewer\EventsApi\Coordinator\CoordinatorInterface;
use Helioviewer\EventsApi\Events\Event;
use Illuminate\Database\Eloquent\Collection;
use Psr\Log\LoggerInterface;

/**
 * Shared machinery for the degree-valued systems (Stonyhurst, Carrington),
 * whose hv_hpc_x/y hold lon/lat and whose footprint vertices are degrees.
 *
 * Events are grouped by coordinate_time and each group is converted with
 * target = that same time, so the coordinator rotates nothing and the call is
 * a pure degrees-to-arcsec conversion. Subclasses supply the endpoint.
 *
 * @package Helioviewer\EventsApi\Coordinator\HPC\Strategies
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
abstract class AbstractHeliographicStrategy implements HPCStrategyInterface
{
    /** Vertices per request: ~450KB JSON, under the coordinator's body limit. */
    protected const VERTEX_CHUNK = 5000;

    protected CoordinatorInterface $coordinator;
    protected ?LoggerInterface $logger;

    /**
     * @param CoordinatorInterface $coordinator Coordinator (wrap in FailoverCoordinator for backup support)
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(CoordinatorInterface $coordinator, ?LoggerInterface $logger = null)
    {
        $this->coordinator = $coordinator;
        $this->logger = $logger;
    }

    /**
     * The coordinate_system value this strategy claims.
     *
     * @return string
     */
    abstract protected function system(): string;

    /**
     * Convert lat/lon degrees to HPC arcsec via the subclass's endpoint.
     *
     * @param array $coordinates Keyed coords with lat, lon, coordinate_time
     * @param int $target Target timestamp
     * @return array Keyed hpc_x/hpc_y, same keys as input
     * @throws CoordinatorException
     */
    abstract protected function convert(array $coordinates, int $target): array;

    /**
     * @param Event $event Event to test
     * @return bool
     */
    public function applies(Event $event): bool
    {
        return $event->coordinate_system === $this->system();
    }

    /**
     * @param Collection $events Events claimed by this strategy
     * @return void
     */
    public function apply(Collection $events): void
    {
        // Garbage lat/lon would be rejected by the coordinator anyway — drop it here.
        $usable = $events->filter(
            fn($event) => is_numeric($event->hv_hpc_x)
                && is_numeric($event->hv_hpc_y)
                && $event->hv_hpc_y >= -90
                && $event->hv_hpc_y <= 90
        );

        $skipped = $events->count() - $usable->count();
        if ($skipped > 0 && $this->logger) {
            $this->logger->warning("HPCResolver | {$this->system()} | skipped {$skipped} events with out-of-range lat/lon");
        }

        // One group per coordinate_time: the conversion is only valid within a time.
        foreach ($usable->groupBy('coordinate_time') as $time => $group) {
            $this->applyForTime((int) $time, $group);
        }
    }

    /**
     * Resolve one coordinate_time group: centers first, then footprint
     * vertices. Anything short of full success leaves the group unset.
     *
     * @param int $time The group's coordinate_time
     * @param Collection $events Events sharing that time
     * @return void
     */
    private function applyForTime(int $time, Collection $events): void
    {
        // Keyed by event id so the reply can be matched back without relying on order.
        $coordinates = [];
        foreach ($events as $event) {
            $coordinates[$event->id] = [
                'lat' => (float) $event->hv_hpc_y,
                'lon' => (float) $event->hv_hpc_x,
                'coordinate_time' => $time,
            ];
        }

        try {
            $centers = $this->convert($coordinates, $time);
        } catch (CoordinatorException $e) {
            $this->logFailure('centers', count($coordinates), $e);
            return;
        }

        // Only events whose center came back can get a complete snapshot.
        $resolved = $events->filter(fn($event) => isset($centers[$event->id]));
        if ($resolved->isEmpty()) {
            return;
        }

        // Footprints are all-or-nothing: a half-converted polygon is worse than none.
        $footprints = $this->convertFootprints($resolved, $time);
        if ($footprints === null) {
            return;
        }

        foreach ($resolved as $event) {
            $center = $centers[$event->id];
            if (!is_numeric($center['hpc_x'] ?? null) || !is_numeric($center['hpc_y'] ?? null)) {
                continue;
            }
            $event->x_hpc = (float) $center['hpc_x'];
            $event->y_hpc = (float) $center['hpc_y'];
            $event->footprint_hpc = $footprints[$event->id] ?? [];
        }

        if ($this->logger) {
            $this->logger->debug("HPCResolver | {$this->system()} | resolved {$resolved->count()} events at {$time}");
        }
    }

    /**
     * Transform every footprint vertex in the group, streamed in chunks.
     *
     * @param Collection $events Events whose centers resolved
     * @param int $time Their shared coordinate_time
     * @return array<string, array>|null Event id => polygon list; null if any chunk failed
     */
    private function convertFootprints(Collection $events, int $time): ?array
    {
        // Vertices are streamed in bounded chunks; a full hole map is tens of thousands.
        $polygons = [];
        $chunk = [];
        $vertexCount = 0;

        $flush = function () use (&$chunk, &$polygons, $time): bool {
            if (empty($chunk)) {
                return true;
            }
            try {
                $result = $this->convert($chunk, $time);
            } catch (CoordinatorException $e) {
                $this->logFailure('footprint vertices', count($chunk), $e);
                return false;
            }
            if (count($result) !== count($chunk)) {
                // A partial chunk would tear polygons — fail the whole group.
                return false;
            }
            foreach ($result as $key => $coords) {
                if (!is_numeric($coords['hpc_x'] ?? null) || !is_numeric($coords['hpc_y'] ?? null)) {
                    continue;
                }
                [$eventId, $polygonIndex] = explode('|', $key);
                $polygons[$eventId][$polygonIndex][] = [
                    'x' => (float) $coords['hpc_x'],
                    'y' => (float) $coords['hpc_y'],
                ];
            }
            $chunk = [];
            return true;
        };

        // Flatten every vertex of every polygon, keyed so polygons can be rebuilt after.
        foreach ($events as $event) {
            if (empty($event->footprint) || !is_array($event->footprint)) {
                continue;
            }
            foreach ($event->footprint as $polygonIndex => $polygon) {
                foreach ($polygon as $vertexIndex => $point) {
                    $chunk["{$event->id}|{$polygonIndex}|{$vertexIndex}"] = [
                        'lat' => (float) $point['y'],
                        'lon' => (float) $point['x'],
                        'coordinate_time' => $time,
                    ];
                    $vertexCount++;
                    if (count($chunk) >= self::VERTEX_CHUNK && !$flush()) {
                        return null;
                    }
                }
            }
        }

        if (!$flush()) {
            return null;
        }

        if ($vertexCount > 0 && $this->logger) {
            $this->logger->debug("HPCResolver | {$this->system()} | transformed {$vertexCount} footprint vertices");
        }

        $result = [];
        foreach ($polygons as $eventId => $indexed) {
            ksort($indexed);
            $result[$eventId] = array_values($indexed);
        }

        return $result;
    }

    /**
     * @param string $stage What was being converted
     * @param int $count Coordinates in the failed request
     * @param CoordinatorException $e The failure
     * @return void
     */
    private function logFailure(string $stage, int $count, CoordinatorException $e): void
    {
        if ($this->logger) {
            $this->logger->error("HPCResolver | {$this->system()} | {$stage} failed for {$count} coordinates | " . $e->getMessage());
        }
    }
}
