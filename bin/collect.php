#!/usr/bin/env php
<?php

declare(strict_types=1);

// === CONTAINER SETUP ===
$container = require __DIR__ . '/../src/container.php';

// === IMPORTS ===
use Helioviewer\EventsApi\Collector\Collector as EventCollector;
use Helioviewer\EventsApi\Utils\TimeRange;
use Helioviewer\EventsApi\Utils\ArgumentParser;

// Coordinate Resolvers
use Helioviewer\EventsApi\Collector\Coordinate\ResolverInterface;
use Helioviewer\EventsApi\Collector\Coordinate\HarpResolver;
use Helioviewer\EventsApi\Collector\Coordinate\NoaaFromHarpResolver;
use Helioviewer\EventsApi\Collector\Coordinate\DirectNoaaResolver;
use Helioviewer\EventsApi\Collector\Coordinate\NoaaFieldResolver;
use Helioviewer\EventsApi\Collector\Coordinate\CataniaFieldResolver;
use Helioviewer\EventsApi\Collector\Coordinate\ModelFieldResolver;

// Sources
use Helioviewer\EventsApi\Sources\CCMC\DonkiFlareSource;
use Helioviewer\EventsApi\Sources\CCMC\DonkiCmeSource;
use Helioviewer\EventsApi\Sources\CCMC\FlareScoreboardSource;

// Processors
use Helioviewer\EventsApi\Processors\CCMC\DonkiFlareProcessor;
use Helioviewer\EventsApi\Processors\CCMC\DonkiCmeProcessor;
use Helioviewer\EventsApi\Processors\CCMC\FlareScoreboard\Processor as FlareScoreboardProcessor;
use Helioviewer\EventsApi\Processors\CCMC\FlareScoreboard\DaffProcessor;

echo "=== Helioviewer Events Collector ===\n";

// === SERVICE SETUP ===
$eventRepository = $container['eventRepository'];
$jsonStorage = $container['jsonStorage'];
$httpClient = $container['httpClient'];
$harpService = $container['harp'];
$noaaService = $container['noaa'];

$collector = new EventCollector($eventRepository, $jsonStorage);

// === SOURCES ===
$collector->addSource('CCMC>>DONKI>>CME', new DonkiCmeSource());
$collector->addSource('CCMC>>DONKI>>Solar Flares', new DonkiFlareSource());

$predictionModels = [
    // 'SIDC_Operator_REGIONS' => 'SIDC Operator',
    // 'BoM_flare1_REGIONS' => 'Bureau of Meteorology',
    // 'ASSA_1_REGIONS' => 'ASSA',
    // 'ASSA_24H_1_REGIONS' => 'ASSA 24H',
    // 'AMOS_v1_REGIONS' => 'AMOS',
    // 'ASAP_1_REGIONS' => 'ASAP',
    // 'NOAA_1_REGIONS' => 'NOAA',
    // 'MAG4_LOS_FEr_REGIONS' => 'MAG4 LoS FEr',
    // 'MAG4_LOS_r_REGIONS' => 'MAG4 LoS r',
    'DAFFS_REGIONS' => 'DAFFS',
];

foreach ($predictionModels as $modelId => $modelName) {
    $collector->addSource("CCMC>>Solar Flare Predictions>>$modelName", new FlareScoreboardSource($modelId, $modelName, $httpClient));
}

// === COORDINATE RESOLVERS ===
// Create individual service-based resolvers
$harpResolver = new HarpResolver($harpService);
$noaaFromHarpResolver = new NoaaFromHarpResolver($noaaService);
$directNoaaResolver = new DirectNoaaResolver($noaaService);

// Create individual field-based resolvers for processors that read from raw record fields
$noaaFieldResolver = new NoaaFieldResolver();
$cataniaFieldResolver = new CataniaFieldResolver();
$modelFieldResolver = new ModelFieldResolver();

// === PROCESSORS ===
// DONKI processors don't need coordinate resolution (coordinates in raw data)
$collector->addProcessor(new DonkiFlareProcessor());
$collector->addProcessor(new DonkiCmeProcessor());

// DAFF processor uses only service-based resolvers
$daffProcessor = new DaffProcessor();
$daffProcessor->addCoordinateResolver($harpResolver);           // ATTEMPT 1: HARP direct lookup
$daffProcessor->addCoordinateResolver($noaaFromHarpResolver);   // ATTEMPT 2: NOAA via HARP logs
$daffProcessor->addCoordinateResolver($directNoaaResolver);     // ATTEMPT 3: NOAA direct lookup
$collector->addProcessor($daffProcessor);

// FlareScoreboard processor uses only field-based resolvers
$flareScoreboardProcessor = new FlareScoreboardProcessor();
$flareScoreboardProcessor->addCoordinateResolver($noaaFieldResolver);      // Try NOAA raw fields
$flareScoreboardProcessor->addCoordinateResolver($cataniaFieldResolver);   // Try Catania raw fields
$flareScoreboardProcessor->addCoordinateResolver($modelFieldResolver);     // Try Model raw fields
$collector->addProcessor($flareScoreboardProcessor);

// === ARGUMENT PARSING ===
$startDate = $argv[1] ?? null;
$endDate = $argv[2] ?? null;

try {
    [$start, $end] = ArgumentParser::parseDateRange($startDate, $endDate);
} catch (InvalidArgumentException $e) {
    echo $e->getMessage() . "\n";
    echo "Usage: php collect.php [start_date] [end_date]\n";
    echo "Examples:\n";
    echo "  php collect.php                    (today)\n";
    echo "  php collect.php 2023-10-25\n";
    echo "  php collect.php 2023-10-25 2023-10-28\n";
    exit(1);
}

$timeRange = TimeRange::fromTimestamps($start, $end);

echo "Collecting events from " . date('Y-m-d H:i:s', $start) . " to " . date('Y-m-d H:i:s', $end) . "\n";
echo "Duration: " . ($end - $start) . " seconds (" . round(($end - $start) / 86400, 1) . " days)\n\n";

// Show source information
echo $collector->getSourceInfo();
echo "\n";

$totalEvents = 0;
$startTime = microtime(true);

try {
    // Collect from all sources
    echo "=== Collecting from all sources ===\n";
    $events = $collector->collect($timeRange);
    $totalEvents = count($events);
    echo "Total events collected: {$totalEvents}\n\n";
    
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
