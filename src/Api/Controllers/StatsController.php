<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;
use Helioviewer\EventsApi\Events\Sources\JsonSource;

class StatsController extends Controller
{
    /**
     * Get comprehensive statistics
     */
    public function getStats(Request $request, Response $response): Response
    {
        try {
            // Get total counts
            $totalEvents = DB::table('events')->count();
            $totalRegions = DB::table('regions')->count();
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
                'unique_sources' => $uniqueSources,
                'today_events' => $todayEvents,
                'week_events' => $weekEvents,
                'date_range' => [
                    'oldest' => $oldestEvent ? date('Y-m-d', $oldestEvent) : null,
                    'newest' => $newestEvent ? date('Y-m-d', $newestEvent) : null,
                ],
            ];

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

            // Map source IDs to names
            $sourceNames = [
                JsonSource::CCMC => 'CCMC',
                JsonSource::HEK => 'HEK',
                JsonSource::RHESSI => 'RHESSI',
            ];

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
                    'source' => $sourceNames[$event->source_id] ?? 'Unknown',
                    'start' => $event->start ? date('Y-m-d H:i:s', $event->start) : null,
                    'updated_at' => $event->updated_at,
                ];
            })->toArray();

            // Build final response
            $stats = [
                'summary' => $summary,
                'by_source_path' => $formattedBySourcePath,
                'by_label' => $formattedByLabel,
                'recent_events' => $formattedRecent,
                'generated_at' => date('c'),
            ];

            return $this->json($response, $stats);

        } catch (\Exception $e) {
            $this->logger->error("Failed to generate statistics: " . $e->getMessage());
            return $this->error($response, 'Failed to generate statistics: ' . $e->getMessage());
        }
    }
}