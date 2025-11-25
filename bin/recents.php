#!/usr/bin/env php
<?php

declare(strict_types=1);

// === BOOTSTRAP ===
require __DIR__ . '/../src/bootstrap.php';

// === IMPORTS ===
use Helioviewer\EventsApi\Utils\Container;

$container = Container::getInstance();

echo "=== Most Recent Events (ordered by creation time) ===\n\n";

try {
    // Parse limit from command line arguments
    $limit = 10; // default
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $limit = (int) $argv[1];
    }
    
    // Get services from container
    $repository = $container['eventRepository'];
    $storage = $container['jsonStorage'];
    
    // Get the recent events
    $recentEvents = $repository->getRecent($limit);
    
    if (empty($recentEvents)) {
        echo "No events found in the database.\n";
        exit(0);
    }
    
    echo "Last " . count($recentEvents) . " events (all fields):\n\n";
    
    // Convert events to array format and include JSON data
    $eventsData = [];
    foreach ($recentEvents as $event) {
        // Check if it's an Event model or array
        $eventArray = $event;
        
        // Convert timestamps to readable format for display
        // The timestamps are numeric (Unix timestamps)
        $eventArray['start'] = isset($eventArray['start']) ? date('Y-m-d H:i:s', $eventArray['start']) : null;
        $eventArray['peak'] = isset($eventArray['peak']) && $eventArray['peak'] ? date('Y-m-d H:i:s', $eventArray['peak']) : null;
        $eventArray['end'] = isset($eventArray['end']) ? date('Y-m-d H:i:s', $eventArray['end']) : null;
        $eventArray['coordinate_time'] = isset($eventArray['coordinate_time']) && $eventArray['coordinate_time'] ? date('Y-m-d H:i:s', $eventArray['coordinate_time']) : null;
        
        // Load and include JSON files
        $uuid = $eventArray['id'];
        
        // Load source JSON data using sharded storage
        $sourceData = $storage->loadById($uuid, 'sources');
        if ($sourceData) {
            $eventArray['source'] = $sourceData;
        }
        
        // Load views JSON data using sharded storage
        $viewsData = $storage->loadById($uuid, 'views');
        if ($viewsData) {
            $eventArray['views'] = $viewsData;
        }
        
        // Load links JSON data using sharded storage
        $linksData = $storage->loadById($uuid, 'links');
        if ($linksData) {
            $eventArray['links'] = $linksData;
        }
        
        $eventsData[] = $eventArray;
    }
    
    // Output as pretty-printed JSON
    echo json_encode($eventsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "\n";
    
} catch (Exception $e) {
    $container['logger']->error("Recent events query failed: " . $e->getMessage());
    $container['logger']->debug("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
