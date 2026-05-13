<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Repositories;

use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Utils\TimeRange;
use Illuminate\Database\Eloquent\Collection;

/**
 * Event Repository Interface
 *
 * Defines the contract for event data persistence and retrieval operations.
 * Implementations handle the storage, querying, and management of solar
 * event data across different persistence layers (database, cache, etc.).
 *
 * The repository pattern abstracts the data access layer, allowing for
 * different storage implementations while maintaining a consistent API
 * for event operations throughout the application.
 *
 * Core responsibilities:
 * - Event persistence and updates
 * - Time-based event queries and filtering
 * - Source-specific event retrieval
 * - Performance optimized queries for large datasets
 * - Data consistency and integrity maintenance
 *
 * @package Helioviewer\EventsApi\Repositories
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
interface RepositoryInterface
{
    /**
     * Persist an Event to the underlying storage system.
     *
     * Handles both new event creation and updates to existing events
     * based on unique identifiers. Implementations should ensure
     * data integrity and handle potential conflicts appropriately.
     *
     * @param Event $event The event instance to save
     *
     * @return Event The saved event instance with any generated fields
     *
     * @throws \RuntimeException If the save operation fails
     */
    public function save(Event $event): Event;

    /**
     * Find events that were active at a specific point in time.
     *
     * Returns events whose time ranges include the specified timestamp,
     * meaning the event was ongoing at that moment. Useful for
     * determining what events were happening at a particular time.
     *
     * @param string $source Source identifier to filter by
     * @param int $timestamp Unix timestamp to check for active events
     *
     * @return Collection<int, Event> Eloquent Collection of Event models active at the given time
     */
    public function findActiveAtTime(string $source, int $timestamp): Collection;

    /**
     * Retrieve events active anywhere within a contiguous time window.
     *
     * Returns events from the given sources whose lifespan intersects
     * [minTime, maxTime]. Use this when consumers need every event in a
     * span (e.g. a single short query). For long spans with sparse sample
     * points, prefer findActiveAtAnyTimestamp() to avoid pulling unrelated rows.
     *
     * @param array<string> $sources Source names to filter by
     * @param int $minTime Start of window (Unix timestamp)
     * @param int $maxTime End of window (Unix timestamp)
     *
     * @return Collection<int, Event>
     */
    public function findActiveInWindow(array $sources, int $minTime, int $maxTime): Collection;

    /**
     * Retrieve events active at ANY of the given timestamps.
     *
     * Built as one SQL with N ORed (start <= t AND end >= t) conditions.
     * For sparse timestamp lists (e.g. a long movie with widely-spaced
     * frames) this is dramatically cheaper than findActiveInWindow(),
     * because only events that intersect at least one requested moment
     * are returned. For dense timestamps the ORed ranges overlap heavily
     * and the cost is similar.
     *
     * @param array<string> $sources    Source names to filter by
     * @param array<int>    $timestamps Unix timestamps to consider
     *
     * @return Collection<int, Event>
     */
    public function findActiveAtAnyTimestamp(array $sources, array $timestamps): Collection;

    /**
     * Retrieve events within a specified time range.
     *
     * Returns events that overlap with the given time range, including
     * events that start before, end after, or are completely contained
     * within the specified range.
     *
     * @param string $source Source identifier to filter by
     * @param TimeRange $timeRange Time range to search within
     *
     * @return array<Event> Array of Event objects within the time range
     */
    public function findByTimeRange(string $source, TimeRange $timeRange): array;

    /**
     * Count the total number of events from a specific source.
     *
     * Provides statistical information about data volume per source,
     * useful for monitoring data collection and system metrics.
     *
     * @param string $source Source identifier to count events for
     *
     * @return int Total number of events from the specified source
     */
    public function countBySource(string $source): int;

    /**
     * Retrieve the most recently created events.
     *
     * Returns events ordered by creation timestamp in descending order,
     * useful for monitoring recent data ingestion and system activity.
     *
     * @param int $limit Maximum number of events to return (default: 100)
     *
     * @return array<Event> Array of the most recent Event objects
     */
    public function getRecent(int $limit = 100): array;

    /**
     * Retrieve a page of events ordered by start time (oldest first).
     *
     * Used by long-running batch jobs (e.g. distribution rebuild, reprocess)
     * to walk the whole events table without holding the entire result set
     * in memory.
     *
     * @param int $page     Page number (0-indexed)
     * @param int $pageSize Number of events per page
     *
     * @return array<Event>
     */
    public function getWithPage(int $page, int $pageSize): array;

    /**
     * Find an event by its remote ID.
     *
     * @param string $remoteId The unique identifier from the source system
     *
     * @return Event|null The matching event record, or null if not found
     */
    public function findByRemoteId(string $remoteId): ?Event;

    /**
     * Get total count of all events in the database.
     *
     * @return int Total number of events
     */
    public function count(): int;

    /**
     * Get statistics grouped by path.
     *
     * @return array Array of path statistics with 'path' and 'count' keys
     */
    public function getStatsByPath(): array;

    /**
     * Get statistics grouped by date.
     *
     * @param int $limit Number of days to return
     * @return array Array of date statistics with 'date' and 'count' keys
     */
    public function getStatsByDate(int $limit = 10): array;

    /**
     * Count events that started recently.
     *
     * @param int $since Unix timestamp to count from
     * @return int Number of events started since the given time
     */
    public function countRecentlyStarted(int $since): int;

    /**
     * Get date range information for all events.
     *
     * @return array|null Array with 'oldest', 'newest', 'earliest_start', 'latest_end' keys
     */
    public function getDateRange(): ?array;

    /**
     * Find an event by its ID (UUID).
     *
     * @param string $id The UUID id of the event
     *
     * @return Event|null The matching event record, or null if not found
     */
    public function findById(string $id): ?Event;

    /**
     * Find events matching any of the given path prefixes that overlap with time range.
     *
     * An event overlaps with [start, end] if: event.start < end AND event.end > start
     *
     * @param array<string> $pathPrefixes Array of path prefixes to match (e.g., ['HEK>>Flare', 'CCMC>>DONKI>>CME'])
     * @param int $start Start timestamp (Unix)
     * @param int $end End timestamp (Unix)
     * @return array<Event> Array of matching Event objects ordered by start time
     */
    public function findByPathPrefixesAndTimeRange(array $pathPrefixes, int $start, int $end): array;

}
