#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reprocess events from stored sources.
 *
 * For every event in the DB (optionally filtered by path prefixes), this script:
 *   1. Loads sources/<uuid>.json
 *   2. Finds the matching source (by path prefix) and processor
 *   3. Runs the full processing pipeline via Collector::processRawRecord(reprocess=true)
 *      which upserts the event row, rewrites views/<uuid>.json and links/<uuid>.json,
 *      and updates region associations.
 *      Distributions are NOT touched (rebuild via `make distribution-build` if needed).
 *      Sources JSON is NOT rewritten (it IS the input).
 *
 * Usage (via make):
 *   make reprocess                                         # dry run, all events
 *   make reprocess APPLY=1                                 # apply, all events
 *   make reprocess PATHS="CCMC>>Solar Flare Predictions"   # dry run, filtered
 *   make reprocess PATHS="HEK,HEK>>Flare" APPLY=1          # apply, filtered
 *
 * Env vars consumed:
 *   PATHS  - comma-separated path prefixes; empty = all events
 *   APPLY  - truthy value (e.g. 1) enables writes; unset/empty/0/false = dry run
 */

ini_set('memory_limit', '2G');

require __DIR__ . '/../src/bootstrap.php';

use Helioviewer\EventsApi\Utils\Container;
use Helioviewer\EventsApi\Utils\SignalHandler;
use Helioviewer\EventsApi\Events\Collector as EventCollector;

SignalHandler::setup();

// === ENV-BASED ARG PARSING ===
$pathFilter = $_ENV['PATHS'] ?? getenv('PATHS') ?: '';
$applyRaw = $_ENV['APPLY'] ?? getenv('APPLY') ?: '';
$apply = $applyRaw !== '' && $applyRaw !== '0' && strcasecmp($applyRaw, 'false') !== 0;

$pathPrefixes = [];
if (trim($pathFilter) !== '') {
    $pathPrefixes = array_filter(array_map('trim', explode(',', $pathFilter)), fn($s) => $s !== '');
}

// === SERVICES ===
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

$collector = EventCollector::createStandard(
    $eventRepository, $regionRepository, $distributionRepository,
    $jsonStorage, $failureStorage, $httpClient, $harpService, $noaaService,
    $logger
);

$sources = $collector->getSources();

// === BANNER ===
$mode = $apply ? 'APPLY' : 'DRY RUN';
$filterDesc = empty($pathPrefixes) ? 'all events' : 'paths: ' . implode(', ', $pathPrefixes);
$logger->info("Starting reprocess [{$mode}] - {$filterDesc}");
if (!$apply) {
    $logger->info("DRY RUN: no DB writes, no JSON writes. Pass 'do' to apply.");
}

$pageSize = 1000;
$totalEvents = $eventRepository->count();
$totalPages = (int) ceil($totalEvents / $pageSize);
$logger->info("Scanning {$totalEvents} events in {$totalPages} pages of {$pageSize}");

$matched = 0;
$reprocessed = 0;
$noProcessor = 0;
$noSource = 0;
$noRaw = 0;
$failed = 0;
$startTime = microtime(true);

try {
    for ($page = 0; $page < $totalPages; $page++) {
        $events = $eventRepository->getWithPage($page, $pageSize);



        foreach ($events as $event) {
            if (!eventMatchesFilter($event->path, $pathPrefixes)) {
                continue;
            }
            $matched++;

            try {
                $source = findSourceForPath($event->path, $sources);
                if (!$source) {
                    $noSource++;
                    $logger->warning("No source for path: {$event->path} (event {$event->id})");
                    continue;
                }

                $raw = $jsonStorage->loadById($event->id, 'sources');
                if (!$raw) {
                    $noRaw++;
                    $logger->warning("No sources/<uuid>.json for event {$event->id} ({$event->path})");
                    continue;
                }

                // Determine the path prefix to feed processRawRecord: source's registered
                // prefix (e.g. "HEK" or "CCMC>>DONKI>>CME") — we derive it by matching.
                $prefix = sourcePrefix($event->path, $sources);

                if (!$apply) {
                    $logger->info("DRY RUN would reprocess: {$event->id} | {$event->path} | prefix={$prefix}");
                    $reprocessed++;
                    continue;
                }

                $saved = $collector->processRawRecord($raw, $source, $prefix, true);
                if ($saved === null) {
                    $noProcessor++;
                    $logger->warning("No processor matched for event {$event->id} ({$event->path})");
                } else {
                    $reprocessed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $logger->warning("Reprocess failed for {$event->id} ({$event->path}): " . $e->getMessage());
            }
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $logger->info("Page {$page}/{$totalPages} - matched={$matched} reprocessed={$reprocessed} noRaw={$noRaw} noSource={$noSource} noProcessor={$noProcessor} failed={$failed} - {$elapsed}s");

        unset($events);
    }
} catch (\Throwable $e) {
    $logger->critical("Reprocess aborted: " . $e->getMessage());
    $logger->debug("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

$duration = round(microtime(true) - $startTime, 2);
$logger->info("Reprocess [{$mode}] completed in {$duration}s: matched={$matched}, reprocessed={$reprocessed}, noRaw={$noRaw}, noSource={$noSource}, noProcessor={$noProcessor}, failed={$failed}");

// ========== Helpers ==========

/**
 * Return true if $path equals any prefix in $prefixes, or starts with "prefix>>".
 * Empty $prefixes means "match everything".
 */
function eventMatchesFilter(string $path, array $prefixes): bool
{
    if (empty($prefixes)) {
        return true;
    }
    foreach ($prefixes as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '>>')) {
            return true;
        }
    }
    return false;
}

/**
 * Match event path against registered source path prefixes.
 */
function findSourceForPath(string $path, array $sources): ?\Helioviewer\EventsApi\Events\Sources\SourceInterface
{
    foreach ($sources as $prefix => $source) {
        if ($path === $prefix || str_starts_with($path, $prefix . '>>')) {
            return $source;
        }
    }
    return null;
}

/**
 * Return the source-registration prefix that matches this event's path.
 * E.g. event.path="HEK>>Flare>>SSW" and sources keyed ["HEK"] => returns "HEK".
 */
function sourcePrefix(string $path, array $sources): string
{
    $bestPrefix = '';
    foreach ($sources as $prefix => $_source) {
        if (($path === $prefix || str_starts_with($path, $prefix . '>>'))
            && strlen($prefix) > strlen($bestPrefix)) {
            $bestPrefix = $prefix;
        }
    }
    return $bestPrefix;
}
