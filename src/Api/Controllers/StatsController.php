<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;
use Helioviewer\EventsApi\Events\Sources\JsonSource;

class StatsController extends Controller
{
    /**
     * Cache TTL in seconds (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * Cache key for statistics
     */
    private const CACHE_KEY = 'stats:dashboard';

    /**
     * Get comprehensive statistics (cached for 1 hour)
     */
    public function getStats(Request $request, Response $response): Response
    {
        try {
            // Check cache first
            $cached = $this->cache->get(self::CACHE_KEY);
            if ($cached !== null) {
                return $this->json($response, $cached);
            }

            // Get total counts
            $totalEvents = DB::table('events')->count();
            $totalRegions = DB::table('regions')->count();
            $totalDistributions = DB::table('distributions')->count();
            $uniqueSources = DB::table('events')->distinct()->count('source_id');

            // Get today's events count
            $todayStart = strtotime('today');
            $todayEnd = strtotime('tomorrow') - 1;
            $todayEvents = DB::table('events')
                ->where('start', '>=', $todayStart)
                ->where('start', '<=', $todayEnd)
                ->count();

            // Get this week's events count
            $weekStart = strtotime('monday this week');
            $weekEnd = strtotime('sunday this week 23:59:59');
            $weekEvents = DB::table('events')
                ->where('start', '>=', $weekStart)
                ->where('start', '<=', $weekEnd)
                ->count();

            // Get date range
            $oldestEvent = DB::table('events')->min('start');
            $newestEvent = DB::table('events')->max('start');

            // Build summary
            $summary = [
                'total_events' => $totalEvents,
                'total_regions' => $totalRegions,
                'total_distributions' => $totalDistributions,
                'unique_sources' => $uniqueSources,
                'today_events' => $todayEvents,
                'week_events' => $weekEvents,
                'date_range' => [
                    'oldest' => $oldestEvent ? date('Y-m-d', $oldestEvent) : null,
                    'newest' => $newestEvent ? date('Y-m-d', $newestEvent) : null,
                ],
            ];

            // Map source IDs to names
            $sourceNames = [
                JsonSource::CCMC => 'CCMC',
                JsonSource::HEK => 'HEK',
                JsonSource::WSA => 'WSA',
                JsonSource::RHESSI => 'RHESSI',
            ];

            // Time boundaries for filtering
            $todayStart = strtotime('today');
            $todayEnd = strtotime('tomorrow') - 1;
            $weekStart = strtotime('monday this week');
            $weekEnd = strtotime('sunday this week 23:59:59');
            $monthStart = strtotime('first day of this month midnight');
            $monthEnd = strtotime('last day of this month 23:59:59');

            // Get events by source with date range and time-based counts
            $bySource = DB::table('events')
                ->select([
                    'source_id',
                    DB::raw('COUNT(*) as count'),
                    DB::raw('MIN(start) as oldest'),
                    DB::raw('MAX(start) as newest'),
                    DB::raw("SUM(CASE WHEN start >= {$todayStart} AND start <= {$todayEnd} THEN 1 ELSE 0 END) as today_count"),
                    DB::raw("SUM(CASE WHEN start >= {$weekStart} AND start <= {$weekEnd} THEN 1 ELSE 0 END) as week_count"),
                    DB::raw("SUM(CASE WHEN start >= {$monthStart} AND start <= {$monthEnd} THEN 1 ELSE 0 END) as month_count")
                ])
                ->groupBy('source_id')
                ->orderBy('count', 'desc')
                ->get();

            $formattedBySource = $bySource->map(function ($item) use ($sourceNames, $totalEvents) {
                return [
                    'source' => $sourceNames[$item->source_id] ?? 'Unknown',
                    'count' => (int)$item->count,
                    'today_count' => (int)$item->today_count,
                    'week_count' => (int)$item->week_count,
                    'month_count' => (int)$item->month_count,
                    'percentage' => $totalEvents > 0 ? round(($item->count / $totalEvents) * 100, 1) : 0,
                    'date_range' => [
                        'oldest' => $item->oldest ? date('Y-m-d', $item->oldest) : null,
                        'newest' => $item->newest ? date('Y-m-d', $item->newest) : null,
                    ],
                ];
            })->toArray();

            // Get events by source and path
            $bySourcePath = DB::table('events')
                ->select([
                    'source_id',
                    'path',
                    DB::raw('COUNT(*) as count')
                ])
                ->groupBy('source_id', 'path')
                ->orderBy('count', 'desc')
                ->limit(20)
                ->get();

            $formattedBySourcePath = $bySourcePath->map(function ($item) use ($sourceNames) {
                return [
                    'source' => $sourceNames[$item->source_id] ?? 'Unknown',
                    'path' => $item->path ?: 'N/A',
                    'count' => (int)$item->count,
                ];
            })->toArray();

            // Get events by label
            $byLabel = DB::table('events')
                ->select([
                    'label',
                    DB::raw('COUNT(*) as count')
                ])
                ->whereNotNull('label')
                ->where('label', '!=', '')
                ->groupBy('label')
                ->orderBy('count', 'desc')
                ->limit(20)
                ->get();

            $formattedByLabel = $byLabel->map(function ($item) {
                return [
                    'label' => $item->label,
                    'count' => (int)$item->count,
                ];
            })->toArray();

            // Get recent events
            $recentEvents = DB::table('events')
                ->orderBy('updated_at', 'desc')
                ->limit(10)
                ->get();

            $formattedRecent = $recentEvents->map(function ($event) use ($sourceNames) {
                return [
                    'id' => $event->id,
                    'label' => $event->label,
                    'path' => $event->path,
                    'start' => $event->start ? date('Y-m-d H:i:s', $event->start) : null,
                    'end' => $event->end ? date('Y-m-d H:i:s', $event->end) : null,
                ];
            })->toArray();

            // Get top distributions by event count
            $topDistributions = DB::table('distributions')
                ->select(['path', 'size', 'start', 'count'])
                ->orderBy('count', 'desc')
                ->limit(20)
                ->get();

            $formattedDistributions = $topDistributions->map(function ($dist) {
                return [
                    'path' => $dist->path,
                    'size' => $dist->size,
                    'start' => date('Y-m-d H:i:s', $dist->start),
                    'count' => (int)$dist->count,
                ];
            })->toArray();

            // Build final response
            $stats = [
                'summary' => $summary,
                'by_source' => $formattedBySource,
                'by_source_path' => $formattedBySourcePath,
                'by_label' => $formattedByLabel,
                'recent_events' => $formattedRecent,
                'top_distributions' => $formattedDistributions,
                'generated_at' => date('c'),
                'cached_until' => date('c', time() + self::CACHE_TTL),
            ];

            // Cache the result for 1 hour
            $this->cache->set(self::CACHE_KEY, $stats, self::CACHE_TTL);

            return $this->json($response, $stats);

        } catch (\Exception $e) {
            $this->logger->error("Failed to generate statistics: " . $e->getMessage());
            $this->sentry->capture($e);
            return $this->error($response, 'Failed to generate statistics: ' . $e->getMessage());
        }
    }
}