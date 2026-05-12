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
 *   make retry-failures LIMIT=50                                     # stop after 50 attempts
 *   make retry-failures HASHES="7f972a3f...,abc123..."                # retry only specific JSONs
 *
 * Env vars consumed:
 *   TYPES    comma-separated subtypes to walk (default: all — coordinate_errors, invalid_events, general_errors)
 *   SOURCES  comma-separated source names (matches the on-disk failure subdir)
 *   HASHES   comma-separated sha256 filenames (no .json suffix) — only files matching are processed
 *   LIMIT    integer cap on the number of failures attempted (0 or unset = no limit)
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
$hashFilter   = $_ENV['HASHES']  ?? getenv('HASHES')  ?: '';
$limitRaw     = $_ENV['LIMIT']   ?? getenv('LIMIT')   ?: '';
$applyRaw     = $_ENV['APPLY']   ?? getenv('APPLY')   ?: '';
$apply        = $applyRaw !== '' && $applyRaw !== '0' && strcasecmp($applyRaw, 'false') !== 0;
$limit        = max(0, (int) $limitRaw);  // 0 = no limit

$typeFilters   = $typeFilter   !== '' ? array_filter(array_map('trim', explode(',', $typeFilter)))   : [];
$sourceFilters = $sourceFilter !== '' ? array_filter(array_map('trim', explode(',', $sourceFilter))) : [];
// Strip optional .json suffix so users can paste filenames verbatim
$hashFilters   = $hashFilter   !== ''
    ? array_filter(array_map(fn($h) => preg_replace('/\.json$/', '', trim($h)), explode(',', $hashFilter)))
    : [];

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
if ($hashFilters)   $logger->info("Hash filter: " . count($hashFilters) . " hash(es)");
if ($limit > 0)     $logger->info("Limit: {$limit} attempt(s)");
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
    'attempted'          => 0,  // counts toward LIMIT
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
            // Hash filter: filename without .json must be in HASHES list
            if ($hashFilters) {
                $hash = preg_replace('/\.json$/', '', basename($file));
                if (!in_array($hash, $hashFilters, true)) continue;
            }

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
                $stats['attempted']++;
                if ($limit > 0 && $stats['attempted'] >= $limit) {
                    $logger->info("Reached LIMIT={$limit}; stopping early.");
                    break 3;
                }
                continue;
            }

            try {
                $saved = $collector->processRawRecord($rawRecord, $source, $pathPrefix, false);
                if ($saved === null) {
                    $stats['no_processor']++;
                    $logger->warning("No processor matched for {$file}");
                } else {
                    // Success — drop the failure JSON
                    if (@unlink($file)) {
                        $stats['deleted']++;
                    }
                    $stats['retried_success']++;
                    $logger->info("Retry SUCCESS: {$type}/{$sourceName} -> event {$saved->id}");
                }
            } catch (\Throwable $e) {
                $stats['retried_still_fail']++;
                $logger->info("Retry still failing: " . failureUrl($type, $sourceName, $file));
                $logger->debug("  └─ " . $e->getMessage());
            }

            $stats['attempted']++;
            if ($limit > 0 && $stats['attempted'] >= $limit) {
                $logger->info("Reached LIMIT={$limit}; stopping early.");
                break 3;
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
    . "considered={$stats['considered']}, attempted={$stats['attempted']}, "
    . "success={$stats['retried_success']}, still_fail={$stats['retried_still_fail']}, "
    . "no_processor={$stats['no_processor']}, no_source_match={$stats['no_source_match']}, "
    . "deleted={$stats['deleted']}"
);

/**
 * Build a clickable URL for a stored failure JSON, served by nginx at
 * /static/failures/<type>/<source>/<hash>.json. Used in dry-run and
 * still-failing log lines so terminals auto-link to the raw record.
 */
function failureUrl(string $type, string $sourceName, string $file): string
{
    $apiUrl = rtrim($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', '/');
    return $apiUrl . '/static/failures/' . $type . '/' . $sourceName . '/' . basename($file);
}

/** Backwards-compatible wrapper used by the dry-run code path. */
function formatDryRunLine(string $type, string $sourceName, string $file, array $rawRecord): string
{
    return failureUrl($type, $sourceName, $file);
}
