<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Regions\Repositories;

use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Regions\Region;
use Illuminate\Database\Eloquent\Collection;
use Helioviewer\EventsApi\Regions\RegionCoordinate;
use Helioviewer\EventsApi\Utils\TimeRange;

/**
 * PostgreSQL-based Region Repository Implementation
 *
 * This repository provides an Eloquent ORM-based implementation for solar region
 * data persistence and retrieval operations. It leverages Laravel's Eloquent ORM
 * for database interactions, providing optimized queries for region and trajectory
 * data access.
 *
 * @package Helioviewer\EventsApi\Regions\Repositories
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class Postgres implements RepositoryInterface
{
    /**
     * Persist a Region to the underlying storage system.
     *
     * @param Region $region The region instance to save
     * @return Region The saved region instance with any generated fields
     * @throws \RuntimeException If the save operation fails
     */
    public function save(Region $region): Region
    {
        try {
            $saved = $region->save();
            
            if (!$saved) {
                throw new \RuntimeException('Failed to save region to database');
            }
            
            $region->refresh();
            return $region;
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to save region: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Find a region by its ID.
     *
     * @param int $id The region ID
     * @return Region|null The matching region or null if not found
     */
    public function findById(int $id): ?Region
    {
        return Region::find($id);
    }

    /**
     * Find a region by organization and external ID.
     *
     * @param string $organization The organization name (NOAA, HARP, etc.)
     * @param string $externalId The external identifier
     * @return Region|null The matching region or null if not found
     */
    public function findByOrganizationAndExternalId(string $organization, string $externalId): ?Region
    {
        return Region::where('organization', $organization)
                    ->where('external_id', $externalId)
                    ->first();
    }

    /**
     * Get all regions for a list of region IDs.
     *
     * @param array $regionIds Array of region IDs
     * @return array Array of Region objects
     */
    public function findByIds(array $regionIds): array
    {
        if (empty($regionIds)) {
            return [];
        }
        
        return Region::whereIn('id', $regionIds)->get()->all();
    }

    /**
     * Get total count of regions.
     *
     * @return int Total number of regions
     */
    public function count(): int
    {
        return Region::count();
    }

    /**
     * Save region coordinate data.
     *
     * @param RegionCoordinate $coordinate The coordinate instance to save
     * @return RegionCoordinate The saved coordinate instance
     * @throws \RuntimeException If the save operation fails
     */
    public function saveCoordinate(RegionCoordinate $coordinate): RegionCoordinate
    {
        try {
            $saved = $coordinate->save();
            
            if (!$saved) {
                throw new \RuntimeException('Failed to save region coordinate to database');
            }
            
            $coordinate->refresh();
            return $coordinate;
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to save region coordinate: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get coordinates for a region within a time range.
     *
     * @param int $regionId The region ID
     * @param TimeRange $timeRange Time range to search within
     * @return array Array of RegionCoordinate objects
     */
    public function getCoordinatesInTimeRange(int $regionId, TimeRange $timeRange): array
    {
        return RegionCoordinate::where('region_id', $regionId)
                              ->whereBetween('time', [$timeRange->start, $timeRange->end])
                              ->orderBy('time')
                              ->get()
                              ->all();
    }

    /**
     * Delete every region left with no events. Their coordinates go too
     * (FK cascade).
     *
     * @return int Number of regions deleted
     */
    public function deleteOrphaned(): int
    {
        return Region::whereDoesntHave('events')->delete();
    }

    /**
     * {@inheritdoc}
     *
     * One query: a join + GROUP BY returning Region models. Eloquent's
     * withCount()/withMax() give the same rows but as two correlated
     * subqueries per region, about 10x slower on 3.7k regions.
     */
    public function getAllWithEventStats(): Collection
    {
        return Region::query()
            ->select('regions.*')
            ->selectRaw('COUNT(event_regions.event_id) AS event_count, MAX(events.start) AS latest_event_start')
            ->leftJoin('event_regions', 'regions.id', '=', 'event_regions.region_id')
            ->leftJoin('events', 'event_regions.event_id', '=', 'events.id')
            ->groupBy('regions.id')
            ->orderBy('regions.organization')
            ->orderBy('regions.external_id')
            ->get();
    }

    /**
     * {@inheritdoc}
     */
    public function findEvents(Region $region, int $limit = 2000): Collection
    {
        return Event::query()
            ->select('events.*')
            ->join('event_regions', 'events.id', '=', 'event_regions.event_id')
            ->where('event_regions.region_id', $region->id)
            ->orderBy('events.start', 'desc')
            ->orderBy('events.id', 'desc')
            ->limit($limit)
            ->get();
    }
}
