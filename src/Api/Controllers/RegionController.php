<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class RegionController extends Controller
{
    /**
     * Get all regions with event counts
     */
    public function getAll(Request $request, Response $response): Response
    {
        try {
            $regions = $this->regionRepository->getAllWithEventStats();

            $formattedRegions = $regions->map(fn($region) => [
                'id' => $region->id,
                'organization' => $region->organization,
                'external_id' => $region->external_id,
                'event_count' => (int) $region->event_count,
                'first_seen' => $region->created_at?->format('Y-m-d H:i:s'),
                'last_updated' => $region->updated_at?->format('Y-m-d H:i:s'),
                'latest_event_start' => $region->latest_event_start ? date('Y-m-d H:i:s', (int) $region->latest_event_start) : null,
            ]);

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

            $region = $this->regionRepository->findByOrganizationAndExternalId($organization, $externalId);

            if (!$region) {
                return $this->error($response, 'Region not found', 404);
            }

            $events = $this->regionRepository->findEvents($region);
            $enhancedEvents = $events->map(fn($event) => $this->enhanceEvent($event));

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
