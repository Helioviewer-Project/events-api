<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

class RegionController extends Controller
{
    /**
     * Get all regions with event counts
     */
    public function getAll(Request $request, Response $response): Response
    {
        try {
            // Get all regions with event counts and latest event start time
            $regions = DB::table('regions')
                ->select([
                    'regions.id',
                    'regions.organization',
                    'regions.external_id',
                    'regions.created_at',
                    'regions.updated_at',
                    DB::raw('COUNT(event_regions.event_id) as event_count'),
                    DB::raw('MAX(events.start) as latest_event_start')
                ])
                ->leftJoin('event_regions', 'regions.id', '=', 'event_regions.region_id')
                ->leftJoin('events', 'event_regions.event_id', '=', 'events.id')
                ->groupBy('regions.id', 'regions.organization', 'regions.external_id', 'regions.created_at', 'regions.updated_at')
                ->orderBy('regions.organization')
                ->orderBy('regions.external_id')
                ->get();

            // Format the response
            $formattedRegions = $regions->map(function ($region) {
                return [
                    'id' => $region->id,
                    'organization' => $region->organization,
                    'external_id' => $region->external_id,
                    'event_count' => (int)$region->event_count,
                    'first_seen' => $region->created_at,
                    'last_updated' => $region->updated_at,
                    'latest_event_start' => $region->latest_event_start ? date('Y-m-d H:i:s', $region->latest_event_start) : null,
                ];
            })->toArray();

            $result = [
                'regions' => $formattedRegions,
                'total_count' => count($formattedRegions),
            ];

            return $this->json($response, $result);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get regions: " . $e->getMessage());
            $this->sentry->capture($e);
            return $this->error($response, 'Failed to retrieve regions');
        }
    }

    /**
     * Get events for a specific region
     */
    public function getByOrganizationAndId(Request $request, Response $response, array $args): Response
    {
        try {
            $organization = strtoupper($args['organization']);
            $externalId = $args['external_id'];

            // Find the region
            $region = DB::table('regions')
                ->where('organization', $organization)
                ->where('external_id', $externalId)
                ->first();

            if (!$region) {
                return $this->error($response, 'Region not found', 404);
            }

            // Get events for this region
            $events = DB::table('events')
                ->select('events.*')
                ->join('event_regions', 'events.id', '=', 'event_regions.event_id')
                ->where('event_regions.region_id', $region->id)
                ->orderBy('events.start', 'desc')
                ->limit(2000)
                ->get();

            // Format events (mirrors EventController shape; events come back as stdClass from the JOIN query)
            $enhancedEvents = $events->map(fn($event) => $this->enhanceEvent((array) $event))->toArray();

            $result = [
                'region' => [
                    'organization' => $region->organization,
                    'external_id' => $region->external_id,
                    'event_count' => count($enhancedEvents),
                ],
                'events' => $enhancedEvents,
            ];

            return $this->json($response, $result);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get region events: " . $e->getMessage());
            $this->sentry->capture($e);
            return $this->error($response, 'Failed to retrieve region events');
        }
    }
}