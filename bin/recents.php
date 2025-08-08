#!/usr/bin/env php
<?php

declare(strict_types=1);

// === CONTAINER SETUP ===
$container = require __DIR__ . '/../src/container.php';

// === IMPORTS ===

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
        $eventArray = $event->toArray();
        
        // Convert timestamps to readable format for display
        // The timestamps are numeric (Unix timestamps)
        $eventArray['start'] = isset($eventArray['start']) ? date('Y-m-d H:i:s', $eventArray['start']) : null;
        $eventArray['peak'] = isset($eventArray['peak']) && $eventArray['peak'] ? date('Y-m-d H:i:s', $eventArray['peak']) : null;
        $eventArray['end'] = isset($eventArray['end']) ? date('Y-m-d H:i:s', $eventArray['end']) : null;
        $eventArray['coordinate_time'] = isset($eventArray['coordinate_time']) && $eventArray['coordinate_time'] ? date('Y-m-d H:i:s', $eventArray['coordinate_time']) : null;
        
        // Load and include JSON files
        $uuid = $eventArray['id'];
        
        // Load source JSON data
        $sourceData = $storage->load("/u/apps/data/sources/{$uuid}.json");
        if ($sourceData) {
            $eventArray['source'] = $sourceData;
        }
        
        // Load views JSON data
        $viewsData = $storage->load("/u/apps/data/views/{$uuid}.json");
        if ($viewsData) {
            $eventArray['views'] = $viewsData;
        }
        
        // Load links JSON data
        $linksData = $storage->load("/u/apps/data/links/{$uuid}.json");
        if ($linksData) {
            $eventArray['links'] = $linksData;
        }
        
        $eventsData[] = $eventArray;
    }
    
    // Output as pretty-printed JSON
    echo json_encode($eventsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
