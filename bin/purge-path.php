#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Delete every trace of one or more event paths: sidecar JSONs
 * (sources/views/links), event rows, their region links, distribution buckets,
 * regions left with no events, and the failure records of the sources feeding
 * those paths.
 *
 * Failure records sit in directories named after the SOURCE
 * (storage/failures/<kind>/FLARE_SCOREBOARD_ASSA_1_REGIONS/), not after the
 * event path, and carry no path of their own. The collector is what ties the
 * two together — it registers each source under a path — so the source names
 * are looked up there rather than asked for.
 *
 * Usage (dry run by default — reports the worklist, deletes nothing):
 *   PATHS="CCMC>>Solar Flare Predictions>>ASSA" php bin/purge-path.php
 *   APPLY=1 PATHS="CCMC>>Solar Flare Predictions>>ASSA" php bin/purge-path.php
 *   APPLY=1 PATHS="WSA" php bin/purge-path.php
 *
 * PATHS - comma-separated event paths. Each matches itself and everything
 *         nested under it: "HEK>>Flare" hits "HEK>>Flare" and
 *         "HEK>>Flare>>SWPC", never the sibling "HEK>>Flare Detective".
 * CHUNK - events per batch (default 500).
 */

ini_set('memory_limit', '2G');

require __DIR__ . '/../src/bootstrap.php';

use Helioviewer\EventsApi\Events\Collector as EventCollector;
use Helioviewer\EventsApi\Utils\Container;
use Helioviewer\EventsApi\Utils\SignalHandler;

SignalHandler::setup();

$apply = getenv('APPLY') === '1';
$chunkSize = getenv('CHUNK') !== false && getenv('CHUNK') !== '' ? max(1, (int) getenv('CHUNK')) : 500;

$paths = array_values(array_filter(
    array_map('trim', explode(',', (string) (getenv('PATHS') ?: ''))),
    fn($s) => $s !== ''
));

if (empty($paths)) {
    fwrite(STDERR, "PATHS is required, e.g. PATHS=\"CCMC>>Solar Flare Predictions>>ASSA\"\n");
    exit(1);
}

$container = Container::getInstance();
$logger = $container['logger'];
$sentry = $container['sentry'];
$eventRepository = $container['eventRepository'];
$distributionRepository = $container['distributionRepository'];
$regionRepository = $container['regionRepository'];
$jsonStorage = $container['jsonStorage'];
$failureStorage = $container['failureStorage'];

$sentry->setTag('Type', 'cli');
$sentry->setTag('Command', 'purge-path');

$logger->info('purge-path [' . ($apply ? 'APPLY' : 'DRY RUN') . '] | paths: ' . implode(', ', $paths));

// === Worklist ===
$byPath = $eventRepository->countByPathTree($paths);
$eventCount = array_sum($byPath);

if ($eventCount === 0) {
    $logger->info('No events match. Nothing to do.');
    exit(0);
}

foreach ($byPath as $path => $count) {
    $logger->info("  {$path}: {$count} events");
}

$distributionCount = $distributionRepository->countByPathTree($paths);

// Ask the collector which sources feed these paths; their names are what the
// failure directories are called.
$collector = EventCollector::createStandard(
    $eventRepository,
    $regionRepository,
    $distributionRepository,
    $jsonStorage,
    $failureStorage,
    $container['httpClient'],
    $container['harp'],
    $container['noaa'],
    $logger,
    $sentry,
    $container['hpcResolver'],
    $container['cache']
);

$inPathTree = function (string $candidate) use ($paths): bool {
    foreach ($paths as $path) {
        if ($candidate === $path || str_starts_with($candidate, $path . '>>')) {
            return true;
        }
    }

    return false;
};

$sourceNames = [];
foreach ($collector->getSources() as $sourcePath => $source) {
    if ($inPathTree($sourcePath)) {
        $sourceNames[$source->getName()] = true;
    }
}
$sourceNames = array_keys($sourceNames);

// Failure records live in flat per-source directories, outside the sharded tree.
$failureFiles = [];
foreach ($sourceNames as $sourceName) {
    foreach (glob('/u/apps/data/failures/*/' . $sourceName . '/*.json') ?: [] as $file) {
        $failureFiles[] = $file;
    }
}

$logger->info('Sources feeding these paths: ' .
              (empty($sourceNames) ? 'none registered in the collector' : implode(', ', $sourceNames)));
$logger->info("Totals | events: {$eventCount} | sidecars: " . ($eventCount * 3) .
              " | distribution buckets: {$distributionCount} | failure records: " . count($failureFiles));

if (!$apply) {
    $logger->info('DRY RUN — nothing deleted. Re-run with APPLY=1 to perform the purge.');
    exit(0);
}

// === Delete: sidecars first, then the rows that point at them ===
$deletedEvents = 0;
$deletedSidecars = 0;

$eventRepository->eachIdInPathTree($paths, $chunkSize, function (array $ids) use (
    $eventRepository, $jsonStorage, $logger, &$deletedEvents, &$deletedSidecars
) {
    foreach ($ids as $id) {
        foreach (['sources', 'views', 'links'] as $type) {
            try {
                $jsonStorage->delete("/u/apps/data/{$type}/{$id}.json");
                $deletedSidecars++;
            } catch (\Throwable $e) {
                $logger->warning("Sidecar delete failed for {$type}/{$id}: {$e->getMessage()}");
            }
        }
    }

    $deletedEvents += $eventRepository->deleteByIds($ids);
    $logger->info("Deleted {$deletedEvents} events so far");
});

$deletedDistributions = $distributionRepository->deleteByPathTree($paths);

// Regions are shared between sources, so only those left with no events at
// all are removed — their coordinates go with them.
$deletedRegions = $regionRepository->deleteOrphaned();

$deletedFailures = 0;
foreach ($failureFiles as $file) {
    $failureStorage->delete($file);
    $deletedFailures++;
}

$logger->info("Purge complete | events: {$deletedEvents} | sidecars: {$deletedSidecars} | " .
              "distribution buckets: {$deletedDistributions} | orphaned regions: {$deletedRegions} | " .
              "failure records: {$deletedFailures}");
$logger->info("Run 'make cache-flush' to drop cached API responses for these paths.");
