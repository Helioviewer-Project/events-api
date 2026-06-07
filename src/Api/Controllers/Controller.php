<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Container\ContainerInterface;
use Helioviewer\EventsApi\Events\Repositories\RepositoryInterface as EventRepositoryInterface;
use Helioviewer\EventsApi\Regions\Repositories\RepositoryInterface as RegionRepositoryInterface;
use Helioviewer\EventsApi\Distributions\Repositories\RepositoryInterface as DistributionRepositoryInterface;
use Helioviewer\EventsApi\Storage\Json\JsonStorageInterface;
use Helioviewer\EventsApi\Jsoc\HarpService;
use Helioviewer\EventsApi\Jsoc\NoaaService;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\Http\Client\ClientInterface;
use Helioviewer\EventsApi\Coordinator\CoordinateRotator;
use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;

/**
 * Abstract base controller with all dependencies
 */
abstract class Controller
{
    protected ContainerInterface $container;

    // Core repositories
    protected EventRepositoryInterface $eventRepository;
    protected RegionRepositoryInterface $regionRepository;
    protected DistributionRepositoryInterface $distributionRepository;

    // Storage services
    protected JsonStorageInterface $jsonStorage;
    protected JsonStorageInterface $failureStorage;

    // Coordinator services
    protected CoordinateRotator $coordinateRotator;

    // External services
    protected HarpService $harpService;
    protected NoaaService $noaaService;

    // Infrastructure
    protected CacheInterface $cache;
    protected LoggerInterface $logger;
    protected ClientInterface $httpClient;
    protected SentryClientInterface $sentry;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        // Initialize all dependencies from container
        $this->eventRepository = $container->get('eventRepository');
        $this->regionRepository = $container->get('regionRepository');
        $this->distributionRepository = $container->get('distributionRepository');

        $this->jsonStorage = $container->get('jsonStorage');
        $this->failureStorage = $container->get('failureStorage');

        $this->coordinateRotator = $container->get('coordinateRotator');

        $this->harpService = $container->get('harp');
        $this->noaaService = $container->get('noaa');

        $this->cache = $container->get('cache');
        $this->logger = $container->get('logger');
        $this->httpClient = $container->get('httpClient');
        $this->sentry = $container->get('sentry');
    }

    /**
     * Helper method to return JSON response
     */
    protected function json(Response $response, $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Helper method to return error response
     */
    protected function error(Response $response, string $message, int $status = 500): Response
    {
        return $this->json($response, ['error' => $message], $status);
    }

    /**
     * Helper method to format timestamp
     */
    protected function formatTimestamp(?int $timestamp): ?string
    {
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    /**
     * Enhance an event with sidecar JSONs, legacy aliases, computed
     * legacy_id, and API URL helpers. Accepts either an Event model or a
     * plain array (matches what the repositories return for batch reads).
     *
     * Per-event shape mirrors `Legacy::normalizeEvent` (used by the
     * `/helioviewer/events/...` endpoints) so consumers get a consistent
     * record across both APIs. Additively keeps the legacy `url` and
     * `source_url` helpers from the v1 contract.
     */
    protected function enhanceEvent(Event|array $event): array
    {
        $eventArray = is_array($event) ? $event : $event->toArray();
        $uuid       = $eventArray['id'] ?? null;

        // Format timestamps (existing v1 contract: space-separated)
        foreach (['start', 'end', 'peak', 'coordinate_time'] as $field) {
            if (!empty($eventArray[$field]) && is_numeric($eventArray[$field])) {
                $eventArray[$field] = $this->formatTimestamp((int) $eventArray[$field]);
            }
        }

        // Legacy aliases (parity with Helioviewer endpoint shape)
        $eventArray['type']       = $eventArray['legacy_type']    ?? null;
        $eventArray['event_type'] = $eventArray['legacy_type']    ?? null;
        $eventArray['version']    = $eventArray['legacy_version'] ?? null;
        $eventArray['pin']        = $eventArray['legacy_pin']     ?? null;

        // Sidecar JSONs (source/views/link)
        if ($uuid !== null) {
            $sourceData = $this->jsonStorage->load("/u/apps/data/sources/{$uuid}.json");
            $eventArray['source'] = $sourceData ?: null;

            $viewsData = $this->jsonStorage->load("/u/apps/data/views/{$uuid}.json");
            $eventArray['views'] = $viewsData ?: [];

            $linksData = $this->jsonStorage->load("/u/apps/data/links/{$uuid}.json");
            if (empty($linksData)) {
                $linksData = [
                    'url'  => Event::getUrlById($uuid),
                    'text' => 'Helioviewer Event URL',
                ];
            }
            $eventArray['link'] = $linksData;

            if (($eventArray['source_id'] ?? null) === JsonSource::HEK) {
                $eventArray['concept'] = $eventArray['source']['concept'] ?? null;
            }
        }

        // legacy_id per source (mirrors Legacy::normalizeEvent)
        if (isset($eventArray['path'])) {
            $path      = $eventArray['path'];
            $pathParts = explode('>>', $path);
            $source    = $eventArray['source'] ?? [];

            if ($path === 'CCMC>>DONKI>>CME') {
                $eventArray['legacy_id'] = $source['activityID'] ?? $eventArray['id'];
            } elseif ($path === 'CCMC>>DONKI>>Solar Flares') {
                $eventArray['legacy_id'] = $source['flrID'] ?? $eventArray['id'];
            } elseif (str_starts_with($path, 'CCMC>>Solar Flare Predictions>>') && count($pathParts) === 3) {
                $eventArray['legacy_id'] = hash('sha256', json_encode($source));
            } elseif (str_starts_with($path, 'RHESSI>>Solar Flares>>Flare') && count($pathParts) === 3) {
                $eventArray['legacy_id'] = $source['id'] ?? $eventArray['id'];
            } elseif (str_starts_with($path, 'HEK>>')) {
                $eventArray['legacy_id'] = $source['kb_archivid'] ?? $eventArray['id'];
            } else {
                $eventArray['legacy_id'] = $eventArray['id'];
            }
        }

        // v1 contract: include API URL helpers alongside id/source (additive)
        if ($uuid !== null) {
            $apiUrl = rtrim($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', '/');
            $eventArray['url']        = "{$apiUrl}/api/v1/events/{$uuid}";
            $eventArray['source_url'] = "{$apiUrl}/api/v1/events/{$uuid}/source";
        }

        return $eventArray;
    }
}
