<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Distributions\Repositories;

use Helioviewer\EventsApi\Events\Event;
use Illuminate\Support\Collection;

/**
 * Distribution Repository Interface
 *
 * Defines the contract for distribution data persistence and retrieval operations.
 * Handles pre-aggregated event counts for fast distribution queries.
 *
 * @package Helioviewer\EventsApi\Distributions\Repositories
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
interface RepositoryInterface
{
    /**
     * Add event to all distribution buckets it overlaps with.
     * Creates distribution records for all bucket sizes (30m, h, D, W, M, Y)
     * that the event's time range intersects.
     *
     * @param Event $event The event to add to distributions
     * @return void
     * @throws \RuntimeException If the operation fails
     */
    public function addEvent(Event $event): void;

    /**
     * Add multiple events to distributions in a single batch operation.
     * Much faster than calling addEvent() individually for bulk imports.
     *
     * @param array<Event> $events Array of Event models to add
     * @return int Number of distribution records affected
     * @throws \RuntimeException If the operation fails
     */
    public function addEventsBatch(array $events): int;

    /**
     * Remove event from all distribution buckets.
     * Decrements counts for all bucket sizes (30m, h, D, W, M, Y)
     * that the event's time range intersects.
     *
     * @param Event $event The event to remove from distributions
     * @return void
     * @throws \RuntimeException If the operation fails
     */
    public function removeEvent(Event $event): void;

    /**
     * Query distribution buckets by path prefix and time range.
     *
     * @param string $pathPrefix Path prefix to filter by (e.g., 'HEK', 'HEK>>Flare')
     * @param string $size       Bucket size: '30m', 'h', 'D', 'W', 'M', or 'Y'
     * @param int    $start      Start timestamp (Unix timestamp)
     * @param int    $end        End timestamp (Unix timestamp)
     * @return Collection<Distribution> Collection of Distribution models
     * @throws \RuntimeException If the query fails
     */
    public function query(string $pathPrefix, string $size, int $start, int $end): Collection;

    /**
     * Query distribution buckets by multiple path prefixes and time range.
     * Returns counts grouped by start time and legacy_event_type.
     *
     * @param array<string> $pathPrefixes Array of path prefixes to query
     * @param string        $size         Bucket size: '30m', 'h', 'D', 'W', 'M', or 'Y'
     * @param int           $start        Start timestamp (Unix timestamp)
     * @param int           $end          End timestamp (Unix timestamp)
     * @return array<int, array<string, int>> Map of start => [legacy_event_type => count]
     * @throws \RuntimeException If the query fails
     */
    public function queryMultiple(array $pathPrefixes, string $size, int $start, int $end): array;

    /**
     * Truncate all distribution data.
     * Used when rebuilding the distribution table from scratch.
     *
     * @return void
     * @throws \RuntimeException If the truncation fails
     */
    public function truncate(): void;
}
