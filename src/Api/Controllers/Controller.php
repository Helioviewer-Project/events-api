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
     * Helper method to enhance event with additional data
     */
    protected function enhanceEvent(Event $event): array
    {
        $eventArray = $event->toArray();
        $uuid = $event->id;

        // Format timestamps
        foreach (['start', 'end', 'peak', 'coordinate_time'] as $field) {
            if (!empty($eventArray[$field])) {
                $eventArray[$field] = $this->formatTimestamp($eventArray[$field]);
            }
        }

        // Add source, views, and links from JSON storage
        $eventArray['source'] = $this->jsonStorage->load("/u/apps/data/sources/{$uuid}.json") ?: null;
        $eventArray['views'] = $this->jsonStorage->load("/u/apps/data/views/{$uuid}.json") ?: [];
        $eventArray['link'] = $this->jsonStorage->load("/u/apps/data/links/{$uuid}.json");

        // Add API URLs - replace id with url, source with source_url
        $apiUrl = rtrim($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', '/');
        $reorderedArray = [];
        foreach ($eventArray as $key => $value) {
            if ($key === 'id') {
                $reorderedArray['url'] = "{$apiUrl}/api/v1/events/{$uuid}";
            } elseif ($key === 'source') {
                $reorderedArray['source_url'] = "{$apiUrl}/api/v1/events/{$uuid}/source";
            } else {
                $reorderedArray[$key] = $value;
            }
        }

        return $reorderedArray;
    }
}
