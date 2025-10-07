<?php

namespace Helioviewer\EventsApi\Api\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Container\ContainerInterface;
use Helioviewer\EventsApi\Events\Repositories\RepositoryInterface as EventRepositoryInterface;
use Helioviewer\EventsApi\Regions\Repositories\RepositoryInterface as RegionRepositoryInterface;
use Helioviewer\EventsApi\Storage\Json\JsonStorageInterface;
use Helioviewer\EventsApi\Jsoc\HarpService;
use Helioviewer\EventsApi\Jsoc\NoaaService;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Abstract base controller with all dependencies
 */
abstract class Controller
{
    protected ContainerInterface $container;

    // Core repositories
    protected EventRepositoryInterface $eventRepository;
    protected RegionRepositoryInterface $regionRepository;

    // Storage services
    protected JsonStorageInterface $jsonStorage;
    protected JsonStorageInterface $failureStorage;

    // Coordinator services
    protected $coordinator;
    protected $backupCoordinator;

    // External services
    protected HarpService $harpService;
    protected NoaaService $noaaService;

    // Infrastructure
    protected CacheInterface $cache;
    protected LoggerInterface $logger;
    protected $httpClient;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        // Initialize all dependencies from container
        $this->eventRepository = $container->get('eventRepository');
        $this->regionRepository = $container->get('regionRepository');

        $this->jsonStorage = $container->get('jsonStorage');
        $this->failureStorage = $container->get('failureStorage');

        $this->coordinator = $container->get('coordinator');
        $this->backupCoordinator = $container->get('backup_coordinator');

        $this->harpService = $container->get('harp');
        $this->noaaService = $container->get('noaa');

        $this->cache = $container->get('cache');
        $this->logger = $container->get('logger');
        $this->httpClient = $container->get('httpClient');
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
    protected function enhanceEvent(array $eventArray): array
    {
        $uuid = $eventArray['id'];

        // Format timestamps
        foreach (['start', 'end', 'peak', 'coordinate_time'] as $field) {
            if (!empty($eventArray[$field])) {
                $eventArray[$field] = $this->formatTimestamp($eventArray[$field]);
            }
        }

        // Add source data from JSON storage
        $sourceData = $this->jsonStorage->load("/u/apps/data/sources/{$uuid}.json");
        if ($sourceData) {
            $eventArray['source'] = $sourceData;
        }

        // Add views from JSON storage
        $viewsData = $this->jsonStorage->load("/u/apps/data/views/{$uuid}.json");
        if ($viewsData) {
            $eventArray['views'] = $viewsData;
        }

        // Add links from JSON storage
        $linksData = $this->jsonStorage->load("/u/apps/data/links/{$uuid}.json");
        if ($linksData) {
            $eventArray['link'] = $linksData;
        }

        return $eventArray;
    }
}
