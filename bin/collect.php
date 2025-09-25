#!/usr/bin/env php
<?php

declare(strict_types=1);

// Set memory limit for collection script
ini_set('memory_limit', '2G');

// === CONTAINER SETUP ===
$container = require __DIR__ . '/../src/container.php';

// === IMPORTS ===
use Helioviewer\EventsApi\Events\Collector as EventCollector;
use Helioviewer\EventsApi\Utils\TimeRange;
use Helioviewer\EventsApi\Utils\ArgumentParser;
use Helioviewer\EventsApi\Utils\SignalHandler;

// === SIGNAL HANDLING ===
SignalHandler::setup();


// === ARGUMENT PARSING ===
$startDate = $argv[1] ?? null;
$endDate = $argv[2] ?? null;
$chunkInterval = $argv[3] ?? null;

try {
    [$start, $end] = ArgumentParser::parseDateRange($startDate, $endDate);
    $intervalDays = ArgumentParser::parseChunkInterval($chunkInterval);
} catch (InvalidArgumentException $e) {
    echo $e->getMessage() . "\n";
    echo "Usage: php collect.php [start_date] [end_date] [chunk_interval_days]\n";
    echo "Examples:\n";
    echo "  php collect.php                         (today, 1-day chunks)\n";
    echo "  php collect.php 2023-10-25              (single day)\n";
    echo "  php collect.php 2023-10-25 2023-10-28   (date range, 1-day chunks)\n";
    echo "  php collect.php 2023-10-25 2023-10-31 3 (date range, 3-day chunks)\n";
    exit(1);
}

$timeRange = TimeRange::fromTimestamps($start, $end);

$duration = $end - $start;
$days = round($duration / 86400, 1);

// Log collection start with chunk interval info
$chunkInfo = $intervalDays > 1 ? " in {$intervalDays}-day chunks" : " in daily chunks";
$logger->info("Starting event collection for " . date('Y-m-d', $start) . " to " . date('Y-m-d', $end) . 
              " ({$days} days total){$chunkInfo}");

// === SERVICE SETUP ===
$eventRepository = $container['eventRepository'];
$regionRepository = $container['regionRepository'];
$jsonStorage = $container['jsonStorage'];
$failureStorage = $container['failureStorage'];
$httpClient = $container['httpClient'];
$harpService = $container['harp'];
$noaaService = $container['noaa'];
$logger = $container['logger'];

// Use the standard collector factory method
$collector = EventCollector::createStandard(
    $eventRepository,
    $regionRepository,
    $jsonStorage,
    $failureStorage,
    $httpClient,
    $harpService,
    $noaaService,
    $logger
);

// Log registered sources
$sources = $collector->getSources();
foreach ($sources as $path => $source) {
    $logger->debug("Source: {$path} => {$source->getName()}");
}

$totalEvents = 0;
$startTime = microtime(true);


try {

    // Collect from all sources with specified chunk interval
    $events = $collector->collect($timeRange, $intervalDays);
    $totalEvents = count($events); 
    
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    
    // Show summary
    $avgRate = round($totalEvents / max($duration, 0.1), 2);
    $logger->info("Collection completed with total {$totalEvents} events, average {$avgRate} events/sec");
    
} catch (\Throwable $e) {
    $logger->critical("Collection failed: " . $e->getMessage());
    $logger->debug("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
