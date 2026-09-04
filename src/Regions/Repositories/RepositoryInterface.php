<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Regions\Repositories;

use Helioviewer\EventsApi\Regions\Region;
use Helioviewer\EventsApi\Regions\RegionCoordinate;
use Helioviewer\EventsApi\Utils\TimeRange;

/**
 * Region Repository Interface
 *
 * Defines the contract for region data persistence and retrieval operations.
 * Handles storage, querying, and management of solar region data and their
 * trajectories across different persistence layers.
 *
 * @package Helioviewer\EventsApi\Regions\Repositories
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
interface RepositoryInterface
{
    /**
     * Persist a Region to the underlying storage system.
     *
     * @param Region $region The region instance to save
     * @return Region The saved region instance with any generated fields
     * @throws \RuntimeException If the save operation fails
     */
    public function save(Region $region): Region;

    /**
     * Find a region by its ID.
     *
     * @param int $id The region ID
     * @return Region|null The matching region or null if not found
     */
    public function findById(int $id): ?Region;

    /**
     * Find a region by organization and external ID.
     *
     * @param string $organization The organization name (NOAA, HARP, etc.)
     * @param string $externalId The external identifier
     * @return Region|null The matching region or null if not found
     */
    public function findByOrganizationAndExternalId(string $organization, string $externalId): ?Region;

    /**
     * Get all regions for a list of region IDs.
     *
     * @param array $regionIds Array of region IDs
     * @return array Array of Region objects
     */
    public function findByIds(array $regionIds): array;

    /**
     * Get all regions.
     *
     * @return array Array of all Region objects
     */
    public function getAll(): array;

    /**
     * Get total count of regions.
     *
     * @return int Total number of regions
     */
    public function count(): int;

    /**
     * Save region coordinate data.
     *
     * @param RegionCoordinate $coordinate The coordinate instance to save
     * @return RegionCoordinate The saved coordinate instance
     * @throws \RuntimeException If the save operation fails
     */
    public function saveCoordinate(RegionCoordinate $coordinate): RegionCoordinate;

    /**
     * Get coordinates for a region within a time range.
     *
     * @param int $regionId The region ID
     * @param TimeRange $timeRange Time range to search within
     * @return array Array of RegionCoordinate objects
     */
    public function getCoordinatesInTimeRange(int $regionId, TimeRange $timeRange): array;

    /**
     * Delete every region left with no events. Their coordinates go too
     * (FK cascade).
     *
     * Regions are shared between sources, so one only falls out of use once
     * the last event referencing it is gone.
     *
     * @return int Number of regions deleted
     */
    public function deleteOrphaned(): int;
}