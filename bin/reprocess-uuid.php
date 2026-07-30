#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Reprocess one (or more) events by UUID, replaying their stored
 * sources/<uuid>.json through the current processor code.
 *
 * The event row is updated in place (its remote_id is unchanged because the
 * source JSON is not modified), so the UUID is preserved. Distributions are not
 * touched and the source JSON is not rewritten (it is the input).
 *
 * Usage (direct):   php bin/reprocess-uuid.php <uuid> [<uuid> ...]
 * Usage (via make): make reprocess-uuid UUID="<uuid>,<uuid>"          # dry run
 *                   make reprocess-uuid UUID="<uuid>" APPLY=1         # apply
 */

require __DIR__ . '/../src/bootstrap.php';

use Helioviewer\EventsApi\Utils\Container;
use Helioviewer\EventsApi\Events\Collector as EventCollector;

// UUIDs from argv and/or the UUID env var (comma-separated).
$uuids = array_slice($argv, 1);
$envUuid = $_ENV['UUID'] ?? getenv('UUID') ?: '';
if ($envUuid !== '') {
    $uuids = array_merge($uuids, array_map('trim', explode(',', $envUuid)));
}
$uuids = array_values(array_filter(array_unique($uuids), fn($u) => $u !== ''));

if (empty($uuids)) {
    fwrite(STDERR, "Usage: reprocess-uuid.php <uuid> [<uuid> ...]  (or UUID=\"a,b\")\n");
    exit(1);
}

$applyRaw = $_ENV['APPLY'] ?? getenv('APPLY') ?: '';
$apply = $applyRaw !== '' && $applyRaw !== '0' && strcasecmp($applyRaw, 'false') !== 0;

$container = Container::getInstance();
$eventRepository = $container['eventRepository'];
$jsonStorage = $container['jsonStorage'];
$logger = $container['logger'];

$collector = EventCollector::createStandard(
    $eventRepository,
    $container['regionRepository'],
    $container['distributionRepository'],
    $jsonStorage,
    $container['failureStorage'],
    $container['httpClient'],
    $container['harp'],
    $container['noaa'],
    $logger,
    hpcResolver: $container['hpcResolver']
);

$sources = $collector->getSources();

/** Longest registered source prefix matching this path, with its source. */
function matchSource(string $path, array $sources): array
{
    $bestPrefix = '';
    $bestSource = null;
    foreach ($sources as $prefix => $source) {
        if (($path === $prefix || str_starts_with($path, $prefix . '>>'))
            && strlen($prefix) > strlen($bestPrefix)) {
            $bestPrefix = $prefix;
            $bestSource = $source;
        }
    }
    return [$bestPrefix, $bestSource];
}

$mode = $apply ? 'APPLY' : 'DRY RUN';
$logger->info("reprocess-uuid [{$mode}] - " . count($uuids) . " uuid(s)");

foreach ($uuids as $uuid) {
    $event = $eventRepository->findById($uuid);
    if (!$event) {
        $logger->warning("Not found: {$uuid}");
        continue;
    }

    $raw = $jsonStorage->loadById($uuid, 'sources');
    if (!$raw) {
        $logger->warning("No sources/<uuid>.json: {$uuid} ({$event->path})");
        continue;
    }

    [$prefix, $source] = matchSource($event->path, $sources);
    if (!$source) {
        $logger->warning("No source for path: {$event->path} ({$uuid})");
        continue;
    }

    if (!$apply) {
        $logger->info("DRY RUN would reprocess: {$uuid} | {$event->path} | prefix={$prefix}");
        continue;
    }

    $saved = $collector->processRawRecord($raw, $source, $prefix, true);
    if ($saved === null) {
        $logger->warning("No processor matched: {$uuid} ({$event->path})");
    } else {
        $logger->info("Reprocessed: {$uuid} | {$event->path}");
    }
}
