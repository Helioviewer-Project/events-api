<?php

declare(strict_types=1);

/**
 * Dependency Injection Container
 * 
 * Simple container that provides access to services from bootstrap.php
 * All services are created once in bootstrap and referenced here.
 */

// === BOOTSTRAP ===
require_once __DIR__ . '/bootstrap.php';

// === CONTAINER DEFINITION ===
$container = [
    // Core services
    'cache' => $redisCache,
    'coordinator' => $coordinator,
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

return $container;