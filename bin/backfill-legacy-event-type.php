#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backfill legacy_event_type for existing distribution records.
 *
 * This script updates all existing distributions that have a NULL legacy_event_type
 * by looking up their path in events_paths_legacy_event_types.php.
 *
 * Usage: php bin/backfill-legacy-event-type.php
 *    or: make distribution-backfill-legacy-type
 */

require __DIR__ . '/../src/bootstrap.php';

use Helioviewer\EventsApi\Distributions\Distribution;
use Helioviewer\EventsApi\Utils\Container;

$container = Container::getInstance();
$logger = $container['logger'];

$logger->info("Starting legacy_event_type backfill");

// Get distinct paths that have NULL legacy_event_type
$paths = Distribution::whereNull('legacy_event_type')
    ->select('path')
    ->distinct()
    ->pluck('path');

$logger->info("Found " . count($paths) . " distinct paths with NULL legacy_event_type");

if (count($paths) === 0) {
    $logger->info("Nothing to backfill - all distributions already have legacy_event_type");
    exit(0);
}

$updated = 0;
$failed = 0;

foreach ($paths as $path) {
    try {
        $legacyType = Distribution::getLegacyEventType($path);

        $count = Distribution::where('path', $path)
            ->whereNull('legacy_event_type')
            ->update(['legacy_event_type' => $legacyType]);

        $updated += $count;
        $logger->debug("Updated {$count} rows: {$path} => {$legacyType}");
    } catch (\RuntimeException $e) {
        $failed++;
        $logger->warning("No legacy_event_type found for path: {$path}");
    }
}

$logger->info("Backfill complete:");
$logger->info("  - Distribution records updated: {$updated}");
$logger->info("  - Paths without matching type: {$failed}");
