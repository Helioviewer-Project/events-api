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
            // Get all regions with event counts
            $regions = DB::table('regions')
                ->select([
                    'regions.id',
                    'regions.organization',
                    'regions.external_id',
                    'regions.created_at',
                    'regions.updated_at',
                    DB::raw('COUNT(event_regions.event_id) as event_count')
                ])
                ->leftJoin('event_regions', 'regions.id', '=', 'event_regions.region_id')
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
                ];
            })->toArray();

            $result = [
                'regions' => $formattedRegions,
                'total_count' => count($formattedRegions),
            ];

            return $this->json($response, $result);

        } catch (\Exception $e) {
            $this->logger->error("Failed to get regions: " . $e->getMessage());
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
                ->limit(100)
                ->get();

            // Format events
            $enhancedEvents = $events->map(function ($event) {
                $eventArray = (array)$event;
                $uuid = $eventArray['id'];

                // Format timestamps
                foreach (['start', 'end', 'peak', 'coordinate_time'] as $field) {
                    if (!empty($eventArray[$field])) {
                        $eventArray[$field] = $this->formatTimestamp($eventArray[$field]);
                    }
                }

                // Load source JSON data
                $sourceData = $this->jsonStorage->load("/u/apps/data/sources/{$uuid}.json");
                $eventArray['source'] = $sourceData ?: null;

                // Load views JSON data
                $viewsData = $this->jsonStorage->load("/u/apps/data/views/{$uuid}.json");
                $eventArray['views'] = $viewsData ?: [];

                // Load links JSON data
                $linksData = $this->jsonStorage->load("/u/apps/data/links/{$uuid}.json");
                $eventArray['link'] = $linksData;

                // Add API links
                $apiUrl = rtrim($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', '/');

                // Replace id with url and source with source_url
                $reorderedArray = [];
                foreach ($eventArray as $key => $value) {
                    if ($key === 'id') {
                        $reorderedArray['url'] = "{$apiUrl}/api/v2/events/{$uuid}";
                    } elseif ($key === 'source') {
                        $reorderedArray['source_url'] = "{$apiUrl}/api/v2/events/{$uuid}/source";
                    } else {
                        $reorderedArray[$key] = $value;
                    }
                }

                return $reorderedArray;
            })->toArray();

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
            return $this->error($response, 'Failed to retrieve region events');
        }
    }
}