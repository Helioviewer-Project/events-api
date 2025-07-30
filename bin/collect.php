#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Helioviewer\EventsApi\Services\EventCollector;
use Helioviewer\EventsApi\Repositories\EloquentRepository;
use Helioviewer\EventsApi\Utils\TimeRange;
use Helioviewer\EventsApi\Utils\ArgumentParser;

// Sources
use Helioviewer\EventsApi\Sources\CCMC\DonkiFlareSource;
use Helioviewer\EventsApi\Sources\CCMC\DonkiCmeSource;
use Helioviewer\EventsApi\Sources\CCMC\FlareScoreboardSource;

// Processors
use Helioviewer\EventsApi\Processors\CCMC\DonkiFlareProcessor;
use Helioviewer\EventsApi\Processors\CCMC\DonkiCmeProcessor;
use Helioviewer\EventsApi\Processors\CCMC\FlareScoreboardProcessor;

use Carbon\Carbon;

echo "=== Helioviewer Events Collector ===\n";

// Create core services directly
$repository = new EloquentRepository();
$collector = new EventCollector($repository);

// Register sources
$collector->addSource(new DonkiFlareSource());
// $collector->addSource(new DonkiCmeSource());

// FlareScoreboard prediction models
$predictionModels = [
    'SIDC_Operator_REGIONS' => 'SIDC Operator',
    'BoM_flare1_REGIONS' => 'Bureau of Meteorology',
    // Add more models as needed
];

foreach ($predictionModels as $modelId => $modelName) {
    // $collector->addSource(new FlareScoreboardSource($modelId, $modelName));
}

// Register processors
$collector->addProcessor(new DonkiFlareProcessor());
$collector->addProcessor(new DonkiCmeProcessor());
$collector->addProcessor(new FlareScoreboardProcessor());

// Parse command line arguments for date range
try {
    [$start, $end] = ArgumentParser::parseDateRange($argv);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Usage: php collect.php [start_date] [end_date]\n";
    echo "Example: php collect.php 2023-10-25 2023-10-28\n";
    exit(1);
}

$timeRange = TimeRange::fromTimestamps($start, $end);

echo "Collecting events from " . date('Y-m-d H:i:s', $start) . " to " . date('Y-m-d H:i:s', $end) . "\n";
echo "Duration: " . ($end - $start) . " seconds (" . round(($end - $start) / 86400, 1) . " days)\n\n";

// Show collector stats
$stats = $collector->getStats();
echo "Available data sources: " . implode(', ', $stats['sources']) . "\n";
echo "Total processors: " . $stats['total_processors'] . "\n\n";

// Check if specific source is requested
$requestedSource = null;
if (isset($argv[3])) {
    $requestedSource = strtoupper($argv[3]);
    echo "Collecting from specific source: {$requestedSource}\n\n";
}

$totalEvents = 0;
$startTime = microtime(true);

try {
    if ($requestedSource) {
        // Collect from specific source
        echo "=== Collecting from {$requestedSource} ===\n";
        $events = $collector->collectEvents($requestedSource, $timeRange);
        $totalEvents += count($events);
        echo "Collected " . count($events) . " events from {$requestedSource}\n\n";
    } else {
        // Collect from all sources
        echo "=== Collecting from all sources ===\n";
        $events = $collector->collectAllEvents($timeRange);
        $totalEvents = count($events);
        echo "Total events collected: {$totalEvents}\n\n";
    }
    
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    
    // Show summary
    echo "=== Collection Summary ===\n";
    echo "Total events processed: {$totalEvents}\n";
    echo "Processing time: {$duration} seconds\n";
    echo "Average: " . round($totalEvents / max($duration, 0.1), 2) . " events/second\n";
    
    // Show updated stats
    $newStats = $collector->getStats();
    echo "Total CCMC events in database: " . $newStats['ccmc_events'] . "\n";
    
    echo "\nCollection completed successfully!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
