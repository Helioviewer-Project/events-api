<?php

declare(strict_types=1);

/**
 * Retry every failed raw record stored under /u/apps/data/failures/.
 *
 * For each failure JSON the script:
 *   1. Loads the raw_record + source name
 *   2. Maps the source name to a registered SourceInterface + pathPrefix
 *   3. Calls Collector::processRawRecord(raw, source, pathPrefix, reprocess=false)
 *      so the event lands in the DB + distributions if it succeeds.
 *   4. On success deletes the failure JSON (so the dashboard shrinks).
 *   5. On still-failing leaves the file in place.
 *
 * Usage (via make):
 *   make retry-failures                                              # dry run, all failures
 *   make retry-failures APPLY=1                                      # apply, all failures
 *   make retry-failures TYPES="coordinate_errors" APPLY=1            # only coord errors
 *   make retry-failures SOURCES="FLARE_SCOREBOARD_DAFFS_REGIONS"     # filter by source
 *
 * Env vars consumed:
 *   TYPES    comma-separated subtypes to walk (default: all — coordinate_errors, invalid_events, general_errors)
 *   SOURCES  comma-separated source names (matches the on-disk failure subdir)
 *   APPLY    truthy = retry + delete on success; unset/empty/0 = dry run (default)
 */

ini_set('memory_limit', '1G');

require __DIR__ . '/../src/bootstrap.php';

use Helioviewer\EventsApi\Utils\Container;
use Helioviewer\EventsApi\Utils\SignalHandler;
use Helioviewer\EventsApi\Events\Collector as EventCollector;

SignalHandler::setup();

const FAILURES_ROOT = '/u/apps/data/failures';

// === ENV ===
$typeFilter   = $_ENV['TYPES']   ?? getenv('TYPES')   ?: '';
$sourceFilter = $_ENV['SOURCES'] ?? getenv('SOURCES') ?: '';
$applyRaw     = $_ENV['APPLY']   ?? getenv('APPLY')   ?: '';
$apply        = $applyRaw !== '' && $applyRaw !== '0' && strcasecmp($applyRaw, 'false') !== 0;

$typeFilters   = $typeFilter   !== '' ? array_filter(array_map('trim', explode(',', $typeFilter)))   : [];
$sourceFilters = $sourceFilter !== '' ? array_filter(array_map('trim', explode(',', $sourceFilter))) : [];

// === SERVICES ===
$container = Container::getInstance();
$collector = EventCollector::createStandard(
    $container['eventRepository'],
    $container['regionRepository'],
    $container['distributionRepository'],
    $container['jsonStorage'],
    $container['failureStorage'],
    $container['httpClient'],
    $container['harp'],
    $container['noaa'],
    $container['logger'],
    $container['sentry']
);
$logger = $container['logger'];

// Build a sourceName -> pathPrefix lookup from the registered sources
$pathBySourceName = [];
$sourceByName     = [];
foreach ($collector->getSources() as $path => $src) {
    $pathBySourceName[$src->getName()] = $path;
    $sourceByName[$src->getName()]     = $src;
}

// === BANNER ===
$mode = $apply ? 'APPLY' : 'DRY RUN';
$logger->info("Starting failure retry [{$mode}]");
if ($typeFilters)   $logger->info("Type filter: " . implode(', ', $typeFilters));
if ($sourceFilters) $logger->info("Source filter: " . implode(', ', $sourceFilters));
if (!$apply) {
    $logger->info("DRY RUN: nothing will be written or deleted. Pass APPLY=1 to apply.");
}

if (!is_dir(FAILURES_ROOT)) {
    $logger->info("No failures dir at " . FAILURES_ROOT . "; nothing to do.");
    exit(0);
}

// === WALK ===
$stats = [
    'considered'         => 0,
    'retried_success'    => 0,
    'retried_still_fail' => 0,
    'no_processor'       => 0,
    'no_source_match'    => 0,
    'unreadable'         => 0,
    'skipped_filter'     => 0,
    'deleted'            => 0,
];

$startTime = microtime(true);

foreach ((scandir(FAILURES_ROOT) ?: []) as $type) {
    if ($type === '.' || $type === '..') continue;
    $typeDir = FAILURES_ROOT . '/' . $type;
    if (!is_dir($typeDir)) continue;
    if ($typeFilters && !in_array($type, $typeFilters, true)) continue;

    foreach ((scandir($typeDir) ?: []) as $sourceName) {
        if ($sourceName === '.' || $sourceName === '..') continue;
        $sourceDir = $typeDir . '/' . $sourceName;
        if (!is_dir($sourceDir)) continue;
        if ($sourceFilters && !in_array($sourceName, $sourceFilters, true)) continue;

        if (!isset($sourceByName[$sourceName])) {
            // The on-disk source name doesn't match any registered source —
            // could be a legacy/removed source. Count and skip.
            $files = glob($sourceDir . '/*.json') ?: [];
            $stats['no_source_match'] += count($files);
            $logger->warning("No registered source matches '{$sourceName}' — skipping " . count($files) . " files");
            continue;
        }

        $source     = $sourceByName[$sourceName];
        $pathPrefix = $pathBySourceName[$sourceName];

        foreach (glob($sourceDir . '/*.json') ?: [] as $file) {
            $stats['considered']++;

            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                $stats['unreadable']++;
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || empty($data['raw_record']) || !is_array($data['raw_record'])) {
                $stats['unreadable']++;
                continue;
            }
            $rawRecord = $data['raw_record'];

            if (!$apply) {
                $logger->info("DRY RUN would retry: " . formatDryRunLine($type, $sourceName, $file, $rawRecord));
                continue;
            }

            try {
                $saved = $collector->processRawRecord($rawRecord, $source, $pathPrefix, false);
                if ($saved === null) {
                    $stats['no_processor']++;
                    $logger->warning("No processor matched for {$file}");
                    continue;
                }
                // Success — drop the failure JSON
                if (@unlink($file)) {
                    $stats['deleted']++;
                }
                $stats['retried_success']++;
                $logger->info("Retry SUCCESS: {$type}/{$sourceName} -> event {$saved->id}");
            } catch (\Throwable $e) {
                $stats['retried_still_fail']++;
                $logger->debug("Retry still failing for {$file}: " . $e->getMessage());
            }
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $logger->info(
            "[{$type}/{$sourceName}] elapsed={$elapsed}s | considered={$stats['considered']}"
            . " success={$stats['retried_success']} still_fail={$stats['retried_still_fail']}"
            . " no_proc={$stats['no_processor']} no_src={$stats['no_source_match']}"
        );
    }
}

$duration = round(microtime(true) - $startTime, 2);
$logger->info(
    "Retry [{$mode}] completed in {$duration}s: "
    . "considered={$stats['considered']}, success={$stats['retried_success']}, "
    . "still_fail={$stats['retried_still_fail']}, no_processor={$stats['no_processor']}, "
    . "no_source_match={$stats['no_source_match']}, deleted={$stats['deleted']}"
);

/**
 * Format the dry-run log line as a clickable link to the stored failure JSON,
 * e.g. https://events.helioviewer.org/static/failures/coordinate_errors/<source>/<hash>.json
 * Most terminals auto-link the URL so you can open the raw record directly.
 */
function formatDryRunLine(string $type, string $sourceName, string $file, array $rawRecord): string
{
    $apiUrl = rtrim($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', '/');
    return $apiUrl . '/static/failures/' . $type . '/' . $sourceName . '/' . basename($file);
}
