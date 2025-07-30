<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Repositories;

use Helioviewer\EventsApi\Models\Event;
use Helioviewer\EventsApi\Sources\AbstractSource;
use Helioviewer\EventsApi\Utils\TimeRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Eloquent-based Event Repository Implementation
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
class EloquentRepository implements EventRepositoryInterface
{
    /**
     * Persist an Event to the underlying storage system.
     *
     * Currently implemented as a no-op since event persistence is handled
     * by dedicated processors in the data ingestion pipeline. This design
     * separates concerns between data collection/processing and querying,
     * allowing for specialized persistence strategies during batch operations.
     *
     * Future implementations may include:
     * - Upsert operations based on remote_id and source_id
     * - Conflict resolution for duplicate events
     * - Transactional batch updates
     * - Event deduplication using response_hash comparison
     *
     * @param Event $event The event instance to save
     *
     * @return Event The saved event instance (currently unchanged)
     *
     * @throws \RuntimeException If the save operation fails
     */
    public function save(Event $event): Event
    {
        // Current implementation delegates persistence to processors
        // This maintains separation of concerns between ingestion and querying
        return $event;
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
     * @return array<int, array<string, mixed>> Array of event records active at the given time
     *
     * @throws \InvalidArgumentException If the source identifier is unknown
     */
    public function findActiveAtTime(string $source, int $timestamp): array
    {
        $sourceId = $this->getSourceId($source);

        // Query events that were active at the specified timestamp
        // This uses an optimized WHERE clause that can leverage indexes
        $events = Event::where('source_id', $sourceId)
            ->where('start', '<=', $timestamp)  // Event had started
            ->where('end', '>=', $timestamp)    // Event had not ended
            ->get();

        return $events->toArray();
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
     * @return array<int, array<string, mixed>> Array of the most recent event records,
     *                                          ordered by creation time (newest first)
     */
    public function getRecent(int $limit = 100): array
    {
        // Query most recent events across all sources
        // Uses created_at index for efficient ordering
        $events = Event::orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $events->toArray();
    }

    /**
     * Find an event by its remote ID and source ID.
     *
     * Retrieves a specific solar event using its unique identifier from the
     * originating source system combined with the source ID. This method is
     * essential for event deduplication, data synchronization, and tracking
     * events across different ingestion cycles.
     *
     * Query Strategy:
     * Uses a compound WHERE clause filtering on both remote_id and source_id
     * to ensure precise event identification. The combination of these fields
     * creates a unique constraint that prevents duplicate events and enables
     * efficient lookups during data processing operations.
     *
     * Use Cases:
     * - Event deduplication during data ingestion
     * - Checking for existing events before insertion
     * - Tracking event updates from source systems
     * - Data consistency validation across ingestion cycles
     *
     * Performance Notes:
     * - Leverages compound index on (remote_id, source_id) for optimal performance
     * - Query complexity: O(log n) with proper indexing
     * - Returns single result or null, minimizing memory usage
     *
     * Error Handling:
     * The method includes comprehensive error handling for database connectivity
     * issues and malformed queries. Database exceptions are caught and wrapped
     * in application-specific exceptions with meaningful error messages.
     *
     * @param string $remoteId The unique identifier from the source system
     * @param int $sourceId The normalized source identifier
     *
     * @return Event|null The matching event record, or null if not found
     *
     * @throws \RuntimeException If database query fails or connection is unavailable
     * @throws \InvalidArgumentException If remoteId is empty or sourceId is invalid
     */
    public function findByRemoteIdAndSource(string $remoteId, int $sourceId): ?Event
    {
        // Validate input parameters
        if (empty($remoteId)) {
            throw new \InvalidArgumentException('Remote ID cannot be empty');
        }

        if ($sourceId <= 0) {
            throw new \InvalidArgumentException('Source ID must be a positive integer');
        }

        try {
            // Query for event using compound WHERE clause for optimal index usage
            // This leverages the compound index on (remote_id, source_id)
            $event = Event::where('remote_id', $remoteId)
                ->where('source_id', $sourceId)
                ->first();

            return $event;
        } catch (\Exception $e) {
            // Wrap database exceptions in application-specific runtime exception
            throw new \RuntimeException(
                "Failed to query event with remote_id '{$remoteId}' and source_id '{$sourceId}': " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
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
        // Use match expression for efficient source name resolution
        // Case-insensitive matching with strict return type validation
        return match (strtoupper($source)) {
            'CCMC' => AbstractSource::CCMC,
            'HEK' => AbstractSource::HEK,
            'WSA' => AbstractSource::WSA,
            'RHESSI' => AbstractSource::RHESSI,
            default => throw new \InvalidArgumentException("Unknown source: $source")
        };
    }
}