<?php

declare(strict_types=1);

/**
 * Dependency Injection Container
 * 
 * Simple container that provides access to services from bootstrap.php
 * All services are created once in bootstrap and referenced here.
 */

use Helioviewer\EventsApi\Container;

// === BOOTSTRAP ===
require_once __DIR__ . '/bootstrap.php';

// === CONTAINER DEFINITION ===
$services = [
    // Core services
    'cache' => $redisCache,
    'coordinator' => $coordinator,
    'backup_coordinator' => $backup_coordinator,
    'jsonStorage' => $jsonStorage,
    'failureStorage' => $failureStorage,
    'eventRepository' => $eventRepository,
    'regionRepository' => $regionRepository,
    'logger' => $logger,

    // HTTP and external services
    'httpClient' => $httpClient,
    'harp' => $harpService,
    'noaa' => $noaaService,
];

// Wrap in Container class that implements ContainerInterface
return new Container($services);