#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Helioviewer\EventsApi\Repositories\EloquentRepository;

echo "=== Most Recent Events (ordered by creation time) ===\n\n";

try {
    // Parse limit from command line arguments
    $limit = 10; // default
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $limit = (int) $argv[1];
    }
    
    // Create repository instance
    $repository = new EloquentRepository();
    
    // Get the recent events
    $recentEvents = $repository->getRecent($limit);
    
    if (empty($recentEvents)) {
        echo "No events found in the database.\n";
        exit(0);
    }
    
    echo "Last " . count($recentEvents) . " events (all fields):\n\n";
    
    // Convert events to array format and display as JSON
    $eventsData = [];
    foreach ($recentEvents as $event) {
        $eventArray = $event->toArray();
        
        // Add formatted date fields for readability
        $eventArray['start_formatted'] = date('Y-m-d H:i:s', $event->start);
        $eventArray['peak_formatted'] = $event->peak ? date('Y-m-d H:i:s', $event->peak) : null;
        $eventArray['end_formatted'] = date('Y-m-d H:i:s', $event->end);
        $eventArray['created_at_formatted'] = $event->created_at ? $event->created_at->format('Y-m-d H:i:s') : null;
        $eventArray['updated_at_formatted'] = $event->updated_at ? $event->updated_at->format('Y-m-d H:i:s') : null;
        
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