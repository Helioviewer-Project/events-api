#!/usr/bin/env php
<?php

declare(strict_types=1);

// === CONTAINER SETUP ===
$container = require __DIR__ . '/../src/container.php';

// === IMPORTS ===
use Helioviewer\EventsApi\Sources\JsonSource;

echo "=== Events Database Statistics ===\n\n";

try {
    // Get repository from container
    $eventRepository = $container['eventRepository'];
    
    // Total events count
    $totalEvents = $eventRepository->count();
    echo "Total Events: {$totalEvents}\n\n";
    
    if ($totalEvents === 0) {
        echo "No events found in the database.\n";
        exit(0);
    }
    
    // Group by source name
    echo "Events by Source:\n";
    echo "Source     | Count\n";
    echo "-----------|----------\n";
    
    // Map source IDs to names
    $sourceNames = [
        JsonSource::CCMC => 'CCMC',
        JsonSource::HEK => 'HEK', 
        JsonSource::WSA => 'WSA',
        JsonSource::RHESSI => 'RHESSI'
    ];
    
    // Get stats by source
    foreach ($sourceNames as $sourceId => $sourceName) {
        $count = $eventRepository->countBySource($sourceName);
        if ($count > 0) {
            echo sprintf("%-10s | %s\n", $sourceName, number_format($count));
        }
    }
    echo "\n";
    
    // Get stats by path
    echo "Events by Path:\n";
    echo "Path                                           | Count\n";
    echo "-----------------------------------------------|----------\n";
    
    $pathStats = $eventRepository->getStatsByPath();
    foreach ($pathStats as $stat) {
        $path = $stat['path'] ?: '(empty)';
        $truncatedPath = strlen($path) > 47 ? substr($path, 0, 44) . '...' : $path;
        echo sprintf("%-47s | %s\n", $truncatedPath, number_format($stat['count']));
    }
    echo "\n";
    
    // Get stats by date
    echo "Events by Start Date (last 10 days):\n";
    echo "Date       | Count\n";
    echo "-----------|----------\n";
    
    $dateStats = $eventRepository->getStatsByDate(10);
    foreach ($dateStats as $stat) {
        echo sprintf("%-10s | %s\n", $stat['date'], number_format($stat['count']));
    }
    echo "\n";
    
    // Recent events (started in last 24 hours)
    $yesterday = time() - 86400;
    $recentCount = $eventRepository->countRecentlyStarted($yesterday);
    echo "Events started in last 24 hours: {$recentCount}\n";
    
    // Date range of events
    $dateRange = $eventRepository->getDateRange();
    if ($dateRange) {
        echo "Creation date range: {$dateRange['oldest']} to {$dateRange['newest']}\n";
        echo "Event time range: {$dateRange['earliest_start']} to {$dateRange['latest_end']}\n";
    }
    
    echo "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}