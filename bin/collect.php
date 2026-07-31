#!/usr/bin/env php
<?php

declare(strict_types=1);

// Set memory limit for collection script
ini_set('memory_limit', '2G');

// === BOOTSTRAP ===
require __DIR__ . '/../src/bootstrap.php';

// === IMPORTS ===
use Helioviewer\EventsApi\Utils\Container;
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

// Optional source filter, by name rather than path — see bin/sources.php.
// Accepts comma or semicolon separated names.
$sourceNames = array_values(array_filter(
    array_map('trim', preg_split('/[,;]/', (string) (getenv('SOURCES') ?: ''))),
    fn($name) => $name !== ''
));

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

// === SERVICE SETUP ===
$container = Container::getInstance();
$eventRepository = $container['eventRepository'];
$regionRepository = $container['regionRepository'];
$distributionRepository = $container['distributionRepository'];
$jsonStorage = $container['jsonStorage'];
$failureStorage = $container['failureStorage'];
$httpClient = $container['httpClient'];
$harpService = $container['harp'];
$noaaService = $container['noaa'];
$logger = $container['logger'];
$sentry = $container['sentry'];

$sentry->setTag('Type', 'cli');
$sentry->setTag('Command', 'collect');

// Log collection start with chunk interval info
$chunkInfo = $intervalDays > 1 ? " in {$intervalDays}-day chunks" : " in daily chunks";
$logger->info("Starting event collection for " . date('Y-m-d', $start) . " to " . date('Y-m-d', $end) .
              " ({$days} days total){$chunkInfo}");

// Use the standard collector factory method
$collector = EventCollector::createStandard(
    $eventRepository,
    $regionRepository,
    $distributionRepository,
    $jsonStorage,
    $failureStorage,
    $httpClient,
    $harpService,
    $noaaService,
    $logger,
    $sentry,
    $container['hpcResolver'],
    $container['cache']
);

// Log registered sources, marking what the SOURCES filter will actually run.
// Asks the collector rather than re-deriving the match, so this cannot drift
// from what collect() does.
$sources = $collector->getSources();
$selected = $collector->selectSources($sourceNames);

foreach ($sources as $path => $source) {
    $mark = isset($selected[$path]) ? 'FETCH' : 'SKIP ';
    $logger->debug("[{$mark}] {$path} => {$source->getName()}");
}

if (!empty($sourceNames)) {
    $logger->info('SOURCES filter active: ' . count($selected) . ' of ' . count($sources) . ' sources will be fetched');
}

$startTime = microtime(true);

try {
    $sentry->withTransaction('cli.collect', 'cli', function() use ($collector, $timeRange, $intervalDays, $sourceNames, $logger, $startTime) {
        // Collect from all sources with specified chunk interval
        // Returns count (not events array) to prevent memory accumulation on large date ranges
        $totalEvents = $collector->collect($timeRange, $intervalDays, $sourceNames);

        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);

        // Show summary
        $avgRate = round($totalEvents / max($duration, 0.1), 2);
        $logger->info("Collection completed with total {$totalEvents} events, average {$avgRate} events/sec");
    });
} catch (\Throwable $e) {
    $sentry->capture($e);
    $logger->critical("Collection failed: " . $e->getMessage());
    $logger->debug("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
