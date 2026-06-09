<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Repositories;

use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Utils\TimeRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * PostgreSQL-based Event Repository Implementation
 *
 * This repository provides an Eloquent ORM-based implementation for solar event
 * data persistence and retrieval operations. It leverages Laravel's Eloquent ORM
 * for database interactions, providing optimized queries for time-based event
 * filtering and source-specific data access.
 *
 * The implementation focuses on efficient query patterns for large-scale solar
 * event data, utilizing database indexes and optimized WHERE clauses for
 * performance. Time-based queries are particularly optimized since solar events
 * are primarily accessed by temporal ranges.
 *
 * Key Features:
 * - Time-based event queries with overlap detection
 * - Source-specific filtering using normalized source IDs
 * - Performance-optimized database queries with proper indexing strategy
 * - Complex temporal logic for event range intersections
 * - Standardized data access patterns following repository pattern
 *
 * Database Interaction Strategy:
 * The repository implements a read-optimized query strategy where:
 * - Time range queries use compound WHERE clauses for optimal index usage
 * - Source filtering is performed using integer constants for performance
 * - Results are returned as arrays for consistency across different storage layers
 * - Query complexity is managed through Eloquent's query builder methods
 *
 * Performance Considerations:
 * - Queries are designed to leverage composite indexes on (source_id, start, end)
 * - Time range overlaps use optimized WHERE conditions to minimize table scans
 * - Result ordering is applied strategically to utilize existing indexes
 * - Large result sets are handled through proper LIMIT clauses
 *
 * @package Helioviewer\EventsApi\Repositories
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class Postgres implements RepositoryInterface
{
    /**
     * Persist an Event to the underlying storage system.
     *
     * Handles both new event creation and updates to existing events using
     * Eloquent ORM. The method automatically determines whether to create
     * a new record or update an existing one based on the event's primary key.
     *
     * Features:
     * - Automatic creation of new events when no ID is present
     * - Updates existing events when ID is present
     * - Timestamp management (created_at/updated_at)
     * - Database transaction safety
     * - Comprehensive error handling with meaningful messages
     *
     * @param Event $event The event instance to save
     *
     * @return Event The saved event instance with updated timestamps
     *
     * @throws \RuntimeException If the save operation fails
     */
    public function save(Event $event): Event
    {
        try {
            // Use Eloquent's save method which handles both create and update
            $saved = $event->save();
            
            if (!$saved) {
                throw new \RuntimeException('Failed to save event to database');
            }
            
            // Refresh the model to get any database-generated values
            $event->refresh();
            
            return $event;
        } catch (\Exception $e) {
            // Wrap database exceptions in application-specific runtime exception
            throw new \RuntimeException(
                "Failed to save event: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Find events that were active at a specific point in time.
     *
     * Retrieves solar events whose temporal ranges encompass the specified
     * timestamp, indicating they were ongoing at that moment. This query
     * is optimized for real-time event status checking and historical
     * event state analysis.
     *
     * Query Logic:
     * An event is considered active at timestamp T if:
     * - event.start <= T (event had already started)
     * - event.end >= T (event had not yet ended)
     *
     * Performance Notes:
     * - Uses compound WHERE clause optimized for (source_id, start, end) index
     * - Filters by source first to reduce query scope
     * - Time conditions leverage range scan capabilities
     *
     * @param string $source Source identifier (CCMC, HEK, WSA, RHESSI)
     * @param int $timestamp Unix timestamp to check for active events
     *
     * @return Collection<int, Event> Eloquent Collection of Event models active at the given time
     *
     * @throws \InvalidArgumentException If the source identifier is unknown
     */
    public function findActiveAtTime(string $source, int $timestamp): Collection
    {
        $sourceId = $this->getSourceId($source);

        // Query events that were active at the specified timestamp
        // This uses an optimized WHERE clause that can leverage indexes
        return Event::where('source_id', $sourceId)
            ->where('start', '<=', $timestamp)  // Event had started
            ->where('end', '>=', $timestamp)    // Event had not ended
            ->get();
    }

    /**
     * Find all events active at any point within a time window across multiple sources.
     *
     * Single query for the superset of events that overlap [minTime, maxTime].
     * Used by batch observations to avoid N queries per source.
     *
     * @param array $sources Array of source names (e.g., ['CCMC', 'HEK'])
     * @param int $minTime Start of window (Unix timestamp)
     * @param int $maxTime End of window (Unix timestamp)
     * @return Collection<int, Event>
     */
    public function findActiveInWindow(array $sources, int $minTime, int $maxTime): Collection
    {
        $sourceIds = array_map(fn($s) => $this->getSourceId($s), $sources);

        return Event::whereIn('source_id', $sourceIds)
            ->where('start', '<=', $maxTime)
            ->where('end', '>=', $minTime)
            ->get();
    }

    /**
     * Return events active at ANY of the given timestamps.
     *
     * Compiles into a single SQL with N ORed (start <= t AND end >= t)
     * conditions. For sparse timestamps this returns ~(N × concurrent-at-each-t)
     * rows instead of every event in the spanning window — orders of magnitude
     * smaller than findActiveInWindow() when the movie covers a long span.
     * For dense timestamps it costs about the same as the window query because
     * the ORed ranges overlap heavily and Postgres dedups internally.
     *
     * @param array<string> $sources    Source names
     * @param array<int>    $timestamps Unix timestamps to consider
     * @return Collection<int, Event>
     */
    public function findActiveAtAnyTimestamp(array $sources, array $timestamps): Collection
    {
        if (empty($sources) || empty($timestamps)) {
            return new Collection();
        }

        $sourceIds = array_map(fn($s) => $this->getSourceId($s), $sources);
        // Dedup so the SQL stays compact if frames coincide on the same instant
        $timestamps = array_values(array_unique($timestamps));

        return Event::whereIn('source_id', $sourceIds)
            ->where(function ($q) use ($timestamps) {
                foreach ($timestamps as $t) {
                    $q->orWhere(function ($sub) use ($t) {
                        $sub->where('start', '<=', $t)
                            ->where('end', '>=', $t);
                    });
                }
            })
            ->get();
    }

    /**
     * Return events matching the given selections AND active at any of the timestamps.
     *
     * Selection logic (OR within the group):
     *   - path-prefix entries match events where `path = prefix` or `path LIKE 'prefix>>%'`
     *   - uuid entries match events where `id IN (...)`
     *
     * Timestamp logic (AND with selections, OR within timestamps):
     *   - events whose [start, end] contains at least one requested timestamp
     *
     * @param array<string> $pathPrefixes Path-prefix selectors (e.g. "HEK", "HEK>>Flare")
     * @param array<string> $uuids        Specific event UUIDs to include
     * @param array<int>    $timestamps   Unix timestamps to consider
     * @return Collection<int, Event>
     */
    public function findActiveAtAnyTimestampForSelections(
        array $pathPrefixes,
        array $uuids,
        array $timestamps
    ): Collection {
        if ((empty($pathPrefixes) && empty($uuids)) || empty($timestamps)) {
            return new Collection();
        }

        $pathPrefixes = array_values(array_unique(array_filter($pathPrefixes, fn($p) => $p !== '')));
        $uuids        = array_values(array_unique(array_filter($uuids,        fn($u) => $u !== '')));
        $timestamps   = array_values(array_unique($timestamps));

        return Event::where(function ($q) use ($pathPrefixes, $uuids) {
            foreach ($pathPrefixes as $prefix) {
                $q->orWhere('path', '=', $prefix);
                $q->orWhere('path', 'LIKE', $prefix . '>>%');
            }
            if (!empty($uuids)) {
                $q->orWhereIn('id', $uuids);
            }
        })
        ->where(function ($q) use ($timestamps) {
            foreach ($timestamps as $t) {
                $q->orWhere(function ($sub) use ($t) {
                    $sub->where('start', '<=', $t)
                        ->where('end', '>=', $t);
                });
            }
        })
        ->get();
    }

    /**
     * Retrieve events within a specified time range.
     *
     * Returns solar events that have any temporal overlap with the given
     * time range. This includes events that start before, end after, or
     * are completely contained within the specified range. The query uses
     * comprehensive overlap detection logic to ensure no relevant events
     * are missed.
     *
     * Overlap Detection Logic:
     * An event overlaps with range [start, end] if ANY of these conditions are true:
     * 1. Event starts within the range (start between range.start and range.end)
     * 2. Event ends within the range (end between range.start and range.end)
     * 3. Event spans the entire range (start <= range.start AND end >= range.end)
     *
     * Query Optimization:
     * - Uses OR conditions within a single WHERE clause for optimal execution
     * - Leverages database query optimizer for best join order
     * - Results ordered by start time for chronological consistency
     * - Source filtering applied first to minimize query scope
     *
     * Performance Characteristics:
     * - Best performance with composite index on (source_id, start, end)
     * - Query complexity: O(log n) with proper indexing
     * - Memory usage scales linearly with result set size
     *
     * @param string $source Source identifier to filter by
     * @param TimeRange $timeRange Time range object containing start and end timestamps
     *
     * @return array<int, array<string, mixed>> Array of event records within the time range,
     *                                          ordered chronologically by start time
     *
     * @throws \InvalidArgumentException If the source identifier is unknown
     */
    public function findByTimeRange(string $source, TimeRange $timeRange): array
    {
        $sourceId = $this->getSourceId($source);

        // Build complex overlap detection query using multiple OR conditions
        // This ensures all events with any temporal intersection are captured
        $events = Event::where('source_id', $sourceId)
            ->where(function (Builder $query) use ($timeRange) {
                // Case 1: Event starts within the time range
                $query->whereBetween('start', [$timeRange->start, $timeRange->end])
                    // Case 2: Event ends within the time range
                    ->orWhereBetween('end', [$timeRange->start, $timeRange->end])
                    // Case 3: Event spans the entire time range
                    ->orWhere(function (Builder $subQuery) use ($timeRange) {
                        $subQuery->where('start', '<=', $timeRange->start)
                            ->where('end', '>=', $timeRange->end);
                    });
            })
            ->orderBy('start')  // Chronological ordering for consistent results
            ->get();

        return $events->toArray();
    }

    /**
     * Count the total number of events from a specific source.
     *
     * Provides statistical information about data volume per source,
     * useful for monitoring data collection effectiveness, system capacity
     * planning, and generating metrics dashboards. The count operation
     * is optimized for performance using database-level aggregation.
     *
     * Performance Notes:
     * - Uses COUNT(*) aggregation at database level for optimal performance
     * - Leverages source_id index for fast filtering
     * - Result is computed server-side to minimize data transfer
     *
     * @param string $source Source identifier to count events for
     *
     * @return int Total number of events from the specified source
     *
     * @throws \InvalidArgumentException If the source identifier is unknown
     */
    public function countBySource(string $source): int
    {
        $sourceId = $this->getSourceId($source);

        // Use database-level COUNT for optimal performance
        return Event::where('source_id', $sourceId)->count();
    }

    /**
     * Retrieve the most recently created events.
     *
     * Returns events ordered by creation timestamp in descending order,
     * providing visibility into recent data ingestion activity. This method
     * is particularly useful for monitoring system health, debugging data
     * collection issues, and displaying recent activity in dashboards.
     *
     * Query Characteristics:
     * - Orders by created_at in descending order (newest first)
     * - Applies LIMIT clause for memory efficiency
     * - No source filtering to show system-wide recent activity
     * - Leverages created_at index for optimal sorting performance
     *
     * Performance Considerations:
     * - Uses created_at index for efficient ORDER BY operation
     * - LIMIT clause prevents excessive memory usage
     * - Result set size is bounded and predictable
     *
     * @param int $limit Maximum number of events to return (default: 100)
     *
     * @return array<Event> Array of the most recent Event objects,
     *                      ordered by creation time (newest first)
     */
    public function getRecent(int $limit = 100): array
    {
        // Query most recent events across all sources
        // Uses updated_at first, then id to break ties for more granular ordering
        // Note: regions are auto-loaded via $with property on Event model
        $events = Event::orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        return $events->toArray();
    }

    /**
     * Find an event by its remote ID.
     *
     * Retrieves a specific solar event using its unique identifier from the
     * originating source system. This method is essential for event deduplication,
     * data synchronization, and tracking events across different ingestion cycles.
     *
     * Query Strategy:
     * Uses a WHERE clause filtering on remote_id to find the event.
     * This assumes remote_id is unique across all sources or that duplicates
     * across sources are acceptable for this use case.
     *
     * Use Cases:
     * - Event deduplication during data ingestion
     * - Checking for existing events before insertion
     * - Tracking event updates from source systems
     * - Data consistency validation across ingestion cycles
     *
     * Performance Notes:
     * - Leverages remote_id index for optimal performance
     * - Query complexity: O(log n) with proper indexing
     * - Returns single result or null, minimizing memory usage
     *
     * Error Handling:
     * The method includes comprehensive error handling for database connectivity
     * issues and malformed queries. Database exceptions are caught and wrapped
     * in application-specific exceptions with meaningful error messages.
     *
     * @param string $remoteId The unique identifier from the source system
     *
     * @return Event|null The matching event record, or null if not found
     *
     * @throws \RuntimeException If database query fails or connection is unavailable
     * @throws \InvalidArgumentException If remoteId is empty
     */
    public function findByRemoteId(string $remoteId): ?Event
    {
        // Validate input parameters
        if (empty($remoteId)) {
            throw new \InvalidArgumentException('Remote ID cannot be empty');
        }

        try {
            // Query for event using WHERE clause for optimal index usage
            // This leverages the remote_id index
            $event = Event::where('remote_id', $remoteId)
                ->first();

            return $event;
        } catch (\Exception $e) {
            // Wrap database exceptions in application-specific runtime exception
            throw new \RuntimeException(
                "Failed to query event with remote_id '{$remoteId}': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get total count of all events in the database.
     *
     * @return int Total number of events
     */
    public function count(): int
    {
        return Event::count();
    }

    /**
     * Get statistics grouped by path.
     *
     * @return array Array of path statistics with 'path' and 'count' keys
     */
    public function getStatsByPath(): array
    {
        return Event::selectRaw('path, COUNT(*) as count')
            ->groupBy('path')
            ->orderBy('count', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Get statistics grouped by date.
     *
     * @param int $limit Number of days to return
     * @return array Array of date statistics with 'date' and 'count' keys
     */
    public function getStatsByDate(int $limit = 10): array
    {
        return Event::selectRaw('DATE(TO_TIMESTAMP(start)) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Count events that started recently.
     *
     * @param int $since Unix timestamp to count from
     * @return int Number of events started since the given time
     */
    public function countRecentlyStarted(int $since): int
    {
        return Event::where('start', '>=', $since)->count();
    }

    /**
     * Get date range information for all events.
     *
     * @return array|null Array with 'oldest', 'newest', 'earliest_start', 'latest_end' keys
     */
    public function getDateRange(): ?array
    {
        $oldestEvent = Event::orderBy('created_at', 'asc')->first();
        $newestEvent = Event::orderBy('created_at', 'desc')->first();
        $earliestStart = Event::orderBy('start', 'asc')->first();
        $latestEnd = Event::orderBy('end', 'desc')->first();

        if (!$oldestEvent || !$newestEvent) {
            return null;
        }

        return [
            'oldest' => $oldestEvent->created_at->format('Y-m-d H:i:s'),
            'newest' => $newestEvent->created_at->format('Y-m-d H:i:s'),
            'earliest_start' => $earliestStart ? date('Y-m-d H:i:s', $earliestStart->start) : null,
            'latest_end' => $latestEnd ? date('Y-m-d H:i:s', $latestEnd->end) : null,
        ];
    }

    /**
     * Find an event by its ID (UUID).
     *
     * @param string $id The UUID id of the event
     *
     * @return Event|null The matching event record, or null if not found
     */
    public function findById(string $id): ?Event
    {
        // find() returns null if not found, doesn't throw exceptions
        return Event::find($id);
    }

    /**
     * Get events with pagination, ordered by start time.
     *
     * @param int $page     Page number (0-indexed)
     * @param int $pageSize Number of events per page
     * @return array<Event> Array of Event objects for the requested page
     */
    public function getWithPage(int $page, int $pageSize): array
    {
        $offset = $page * $pageSize;

        return Event::orderBy('start')
            ->offset($offset)
            ->limit($pageSize)
            ->get()
            ->all();
    }

    /**
     * Page over events matching any of the given path prefixes, ordered by
     * start time — same semantics as getWithPage() but path-restricted.
     *
     * @param array<string> $pathPrefixes
     * @return array<Event>
     */
    public function getByPathPrefixesWithPage(array $pathPrefixes, int $page, int $pageSize): array
    {
        $pathPrefixes = array_values(array_filter($pathPrefixes, fn($p) => $p !== ''));
        if (empty($pathPrefixes)) {
            return [];
        }

        $offset = $page * $pageSize;

        return Event::where(function (Builder $query) use ($pathPrefixes) {
                foreach ($pathPrefixes as $prefix) {
                    $query->orWhere('path', '=', $prefix);
                    $query->orWhere('path', 'LIKE', $prefix . '>>%');
                }
            })
            ->orderBy('start')
            ->offset($offset)
            ->limit($pageSize)
            ->get()
            ->all();
    }

    /**
     * Count events matching any of the given path prefixes
     * (path = prefix OR path LIKE 'prefix>>%').
     *
     * @param array<string> $pathPrefixes
     */
    public function countByPathPrefixes(array $pathPrefixes): int
    {
        $pathPrefixes = array_values(array_filter($pathPrefixes, fn($p) => $p !== ''));
        if (empty($pathPrefixes)) {
            return 0;
        }

        return Event::where(function (Builder $query) use ($pathPrefixes) {
            foreach ($pathPrefixes as $prefix) {
                $query->orWhere('path', '=', $prefix);
                $query->orWhere('path', 'LIKE', $prefix . '>>%');
            }
        })->count();
    }

    /**
     * Find events matching any of the given path prefixes that overlap with time range.
     *
     * An event overlaps with [start, end] if: event.start < end AND event.end > start
     *
     * @param array<string> $pathPrefixes Array of path prefixes to match
     * @param int $start Start timestamp (Unix)
     * @param int $end End timestamp (Unix)
     * @return array<Event> Array of matching Event objects ordered by start time
     */
    public function findByPathPrefixesAndTimeRange(array $pathPrefixes, int $start, int $end): array
    {
        if (empty($pathPrefixes)) {
            return [];
        }

        return Event::where(function (Builder $query) use ($pathPrefixes) {
                foreach ($pathPrefixes as $prefix) {
                    $query->orWhere('path', 'LIKE', $prefix . '%');
                }
            })
            ->where('start', '<', $end)
            ->where('end', '>', $start)
            ->orderBy('start')
            ->get()
            ->all();
    }

    /**
     * Convert source name to normalized source ID.
     *
     * Maps human-readable source names to their corresponding integer
     * identifiers as defined in AbstractSource constants. This normalization
     * enables efficient database queries using integer comparisons and
     * maintains consistency across the system.
     *
     * Source Mapping:
     * - CCMC: Community Coordinated Modeling Center (ID: 1)
     * - HEK: Heliophysics Event Knowledgebase (ID: 2)
     * - WSA: Wang-Sheeley-Arge (ID: 3)
     * - RHESSI: Reuven Ramaty High Energy Solar Spectroscopic Imager (ID: 4)
     *
     * The mapping uses PHP 8.0+ match expressions for efficient,
     * case-insensitive source name resolution with strict type checking.
     *
     * @param string $source Source name (case-insensitive)
     *
     * @return int Corresponding source ID constant
     *
     * @throws \InvalidArgumentException If the source name is not recognized
     */
    private function getSourceId(string $source): int
    {
        return JsonSource::getSourceId($source);
    }
}
