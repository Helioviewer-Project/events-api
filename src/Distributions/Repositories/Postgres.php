<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Distributions\Repositories;

use Carbon\Carbon;
use Helioviewer\EventsApi\Distributions\Distribution;
use Helioviewer\EventsApi\Events\Event;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * PostgreSQL-based Distribution Repository Implementation
 *
 * This repository provides an Eloquent ORM-based implementation for distribution
 * data persistence and retrieval operations. It includes caching for query results
 * and bucket calculation logic for aggregating events into time buckets.
 *
 * @package Helioviewer\EventsApi\Distributions\Repositories
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class Postgres implements RepositoryInterface
{
    /**
     * All supported bucket sizes.
     * 30m = half-hour, h = hour, D = day, W = week, M = month, Y = year
     */
    private const BUCKET_SIZES = ['30m', 'h', 'D', 'W', 'M', 'Y'];

    /**
     * Cache TTL in seconds (1 minute)
     */
    private const CACHE_TTL = 60;

    /**
     * @var CacheInterface PSR-16 cache implementation
     */
    private CacheInterface $cache;

    /**
     * @var LoggerInterface PSR-3 logger implementation
     */
    private LoggerInterface $logger;

    /**
     * @param CacheInterface  $cache  PSR-16 cache implementation
     * @param LoggerInterface $logger PSR-3 logger implementation
     */
    public function __construct(CacheInterface $cache, LoggerInterface $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function addEvent(Event $event): void
    {
        try {
            $buckets = $this->getOverlappingBuckets($event);
            $buckets->map(fn($dist) => $dist->increment('count'));

            // Log summary of bucket spans
            $summary = $this->formatBucketSummary($buckets);
            $this->logger->debug("Distribution +1: {$event->path} | {$summary}");
        } catch (\Exception $e) {
            $this->logger->error("addEvent failed: " . $e->getMessage());
            throw new \RuntimeException(
                "Failed to add event to distribution: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Add multiple events to distributions in a single batch operation.
     * Much faster than calling addEvent() individually for bulk imports.
     *
     * @param array<Event> $events Array of Event models to add
     * @return int Number of distribution records affected
     */
    public function addEventsBatch(array $events): int
    {
        if (empty($events)) {
            return 0;
        }

        try {
            // Step 1: Calculate all buckets for all events and count occurrences
            $bucketCounts = [];

            foreach ($events as $event) {
                foreach (self::BUCKET_SIZES as $bucketSize) {
                    $current = self::getBucketStart($event->start, $bucketSize);

                    while ($current < $event->end) {
                        $key = "{$bucketSize}|{$event->path}|{$current}";

                        if (!isset($bucketCounts[$key])) {
                            $bucketCounts[$key] = [
                                'size' => $bucketSize,
                                'path' => $event->path,
                                'start' => $current,
                                'count' => 0,
                            ];
                        }
                        $bucketCounts[$key]['count']++;

                        $current = self::getNextBucketStart($current, $bucketSize);
                    }
                }
            }

            if (empty($bucketCounts)) {
                return 0;
            }

            // Step 2: Upsert all buckets with their counts
            $now = Carbon::now();
            $rows = [];

            foreach ($bucketCounts as $bucket) {
                $rows[] = [
                    'size' => $bucket['size'],
                    'path' => $bucket['path'],
                    'start' => $bucket['start'],
                    'count' => $bucket['count'],
                    'legacy_event_type' => Distribution::getLegacyEventType($bucket['path']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Chunk to stay under PostgreSQL's 65535 parameter limit
            // Each row has 6 columns, so 1000 rows = 6000 parameters (safe margin)
            $chunks = array_chunk($rows, 1000);

            foreach ($chunks as $chunk) {
                // Use upsert - on conflict, add to existing count
                Distribution::upsert(
                    $chunk,
                    ['size', 'path', 'start'],  // unique columns
                    ['count' => Capsule::raw('distributions.count + EXCLUDED.count'), 'updated_at']
                );
            }

            return count($rows);
        } catch (\Exception $e) {
            $this->logger->error("addEventsBatch failed: " . $e->getMessage());
            throw new \RuntimeException(
                "Failed to add events batch to distribution: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeEvent(Event $event): void
    {
        try {
            $buckets = $this->getOverlappingBuckets($event);
            $buckets->map(fn($dist) => $dist->decrement('count'));

            // Log summary of bucket spans
            $summary = $this->formatBucketSummary($buckets);
            $this->logger->debug("Distribution -1: {$event->path} | {$summary}");
        } catch (\Exception $e) {
            $this->logger->error("removeEvent failed: " . $e->getMessage());
            throw new \RuntimeException(
                "Failed to remove event from distribution: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function query(string $pathPrefix, string $size, int $start, int $end): Collection
    {
        try {
            // Check cache first
            $cacheKey = $this->getCacheKey($pathPrefix, $size, $start, $end);
            $cached = $this->cache->get($cacheKey);

            if ($cached !== null) {
                return collect($cached)->map(fn($item) => new Distribution($item));
            }

            // Query database
            $distributions = Distribution::where('size', $size)
                ->where('path', 'LIKE', $pathPrefix . '%')
                ->whereBetween('start', [$start, $end])
                ->orderBy('start')
                ->get();

            // Cache result
            $this->cache->set($cacheKey, $distributions->toArray(), self::CACHE_TTL);

            return $distributions;
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to query distribution: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Generate cache key for distribution query.
     *
     * @param string $pathPrefix Path prefix
     * @param string $size       Bucket size
     * @param int    $start      Start timestamp
     * @param int    $end        End timestamp
     * @return string Cache key
     */
    private function getCacheKey(string $pathPrefix, string $size, int $start, int $end): string
    {
        return "distribution:{$size}:{$pathPrefix}:{$start}:{$end}";
    }

    /**
     * {@inheritdoc}
     */
    public function truncate(): void
    {
        try {
            Distribution::truncate();
        } catch (\Exception $e) {
            throw new \RuntimeException(
                "Failed to truncate distribution: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get all Distribution models for all bucket sizes an event overlaps with.
     * Uses firstOrCreate to find existing or create new records in the database.
     *
     * How bucket overlap is calculated:
     * ---------------------------------
     * For each bucket size, we find all buckets that the event touches.
     * A bucket is identified by its start timestamp (aligned to interval boundaries).
     *
     * Example: Event from 10:20 to 11:45 (duration: 1h 25m)
     *
     *   Bucket 'h' (hourly):
     *   - getBucketStart(10:20) = 10:00  (floor to hour boundary)
     *   - 10:00 < 11:45? Yes -> add bucket 10:00
     *   - Next: 10:00 + 1h = 11:00
     *   - 11:00 < 11:45? Yes -> add bucket 11:00
     *   - Next: 11:00 + 1h = 12:00
     *   - 12:00 < 11:45? No -> stop
     *   - Result: [10:00, 11:00] (2 hourly buckets)
     *
     *   Bucket 'D' (daily):
     *   - getBucketStart(10:20) = 00:00 (floor to day boundary)
     *   - 00:00 < 11:45? Yes -> add bucket 00:00
     *   - Next: 00:00 + 1D = next day 00:00
     *   - next day 00:00 < 11:45? No -> stop
     *   - Result: [00:00] (1 daily bucket)
     *
     * @param Event $event The event to find overlapping buckets for
     * @return Collection<Distribution> Collection of persisted Distribution models
     */
    public function getOverlappingBuckets(Event $event): Collection
    {
        $distributions = collect();
        $startDate = date('Y-m-d H:i:s', $event->start);
        $endDate = date('Y-m-d H:i:s', $event->end);
        // $this->logger->debug("getOverlappingBuckets: path={$event->path}, start={$startDate}, end={$endDate}");

        foreach (self::BUCKET_SIZES as $bucketSize) {
            $bucketCount = 0;

            // Step 1: Calculate all bucket start timestamps this event overlaps with
            // Start from the bucket containing event->start, iterate until we pass event->end
            $current = self::getBucketStart($event->start, $bucketSize);
            $currentDate = date('Y-m-d H:i:s', $current);
            // $this->logger->debug("  {$bucketSize}: first bucket={$currentDate}, end={$endDate}");

            while ($current < $event->end) {
                $currentDate = date('Y-m-d H:i:s', $current);
                // Step 2: Use firstOrCreate to find existing or create new (and save) in one query
                $distribution = Distribution::firstOrCreate(
                    [
                        'size' => $bucketSize,
                        'path' => $event->path,
                        'start' => $current,
                    ],
                    [
                        'count' => 0,
                        'legacy_event_type' => Distribution::getLegacyEventType($event->path),
                    ]
                );
                $created = $distribution->wasRecentlyCreated ? 'NEW' : 'EXISTS';
                // $this->logger->debug("  {$bucketSize}: {$currentDate} -> id={$distribution->id} [{$created}]");
                $distributions->push($distribution);
                $current = self::getNextBucketStart($current, $bucketSize);
                $bucketCount++;
            }

            // $this->logger->debug("  {$bucketSize}: {$bucketCount} buckets total");
        }

        // $this->logger->debug("getOverlappingBuckets: {$distributions->count()} distributions total");
        return $distributions;
    }

    /**
     * Get the start timestamp of the bucket containing the given timestamp.
     * All bucket sizes use calendar-aligned boundaries via Carbon.
     *
     * Examples for timestamp 2024-03-15 10:45:23:
     *   30m -> 2024-03-15 10:30:00 (half-hour boundary: :00 or :30)
     *   h   -> 2024-03-15 10:00:00 (start of hour)
     *   D   -> 2024-03-15 00:00:00 (start of day)
     *   W   -> 2024-03-11 00:00:00 (start of week, Monday)
     *   M   -> 2024-03-01 00:00:00 (start of month)
     *   Y   -> 2024-01-01 00:00:00 (start of year)
     *
     * @param int    $timestamp  Unix timestamp to find bucket for
     * @param string $bucketSize Bucket size: '30m', 'h', 'D', 'W', 'M', or 'Y'
     * @return int Unix timestamp of the bucket start
     * @throws \InvalidArgumentException If bucket size is unknown
     */
    public static function getBucketStart(int $timestamp, string $bucketSize): int
    {
        $dt = Carbon::createFromTimestamp($timestamp, 'UTC');

        return match ($bucketSize) {
            '30m' => $dt->setMinute($dt->minute < 30 ? 0 : 30)->setSecond(0)->timestamp,
            'h'   => $dt->startOfHour()->timestamp,
            'D'   => $dt->startOfDay()->timestamp,
            'W'   => $dt->startOfWeek()->timestamp,
            'M'   => $dt->startOfMonth()->timestamp,
            'Y'   => $dt->startOfYear()->timestamp,
            default => throw new \InvalidArgumentException("Unknown bucket size: $bucketSize"),
        };
    }

    /**
     * Get the start timestamp of the next bucket after the given bucket start.
     * Uses Carbon to correctly handle calendar boundaries (e.g., month/year transitions).
     *
     * @param int    $bucketStart Unix timestamp of the current bucket start
     * @param string $bucketSize  Bucket size: '30m', 'h', 'D', 'W', 'M', or 'Y'
     * @return int Unix timestamp of the next bucket start
     * @throws \InvalidArgumentException If bucket size is unknown
     */
    public static function getNextBucketStart(int $bucketStart, string $bucketSize): int
    {
        $dt = Carbon::createFromTimestamp($bucketStart, 'UTC');

        return match ($bucketSize) {
            '30m' => $dt->addMinutes(30)->timestamp,
            'h'   => $dt->addHour()->timestamp,
            'D'   => $dt->addDay()->timestamp,
            'W'   => $dt->addWeek()->timestamp,
            'M'   => $dt->addMonth()->timestamp,
            'Y'   => $dt->addYear()->timestamp,
            default => throw new \InvalidArgumentException("Unknown bucket size: $bucketSize"),
        };
    }

    /**
     * Format a summary of bucket counts by size.
     * Example output: "1Y, 2M, 3W, 5D, 10h, 20 30m"
     *
     * @param Collection $buckets Collection of Distribution models
     * @return string Formatted summary string
     */
    private function formatBucketSummary(Collection $buckets): string
    {
        $counts = [];
        foreach (self::BUCKET_SIZES as $size) {
            $counts[$size] = 0;
        }

        foreach ($buckets as $bucket) {
            $counts[$bucket->size]++;
        }

        $parts = [];
        foreach ($counts as $size => $count) {
            if ($count > 0) {
                $parts[] = "{$count}x{$size}";
            }
        }

        return implode(', ', $parts);
    }
}
