#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backfill the native-HPC snapshot fields (x_hpc, y_hpc, footprint_hpc) for
 * existing events, via HPCResolver. Resumable: only rows with
 * footprint_hpc IS NULL are picked up, so a re-run continues where the last
 * one stopped. updated_at is never touched.
 *
 * Usage (dry run by default — reports the worklist, no coordinator calls):
 *   php bin/backfill-hpc.php
 *   APPLY=1 php bin/backfill-hpc.php
 *   APPLY=1 PATHS="HEK,CCMC>>Solar Flare Predictions" CHUNK=200 php bin/backfill-hpc.php
 *   APPLY=1 FORCE=1 php bin/backfill-hpc.php   (recompute already-filled rows too)
 *
 * PATHS - comma-separated path prefixes (matches events whose path starts
 *         with any of them); empty = all events
 */

ini_set('memory_limit', '2G');

require __DIR__ . '/../src/bootstrap.php';

use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Utils\Container;
use Helioviewer\EventsApi\Utils\SignalHandler;
use Illuminate\Database\Capsule\Manager as Capsule;

SignalHandler::setup();

$apply = getenv('APPLY') === '1';
$force = getenv('FORCE') === '1';
$chunkSize = getenv('CHUNK') !== false && getenv('CHUNK') !== '' ? max(1, (int) getenv('CHUNK')) : 500;

$pathFilter = $_ENV['PATHS'] ?? getenv('PATHS') ?: '';
$pathPrefixes = [];
if (trim($pathFilter) !== '') {
    $pathPrefixes = array_filter(array_map('trim', explode(',', $pathFilter)), fn($s) => $s !== '');
}

$container = Container::getInstance();
$logger = $container['logger'];
$sentry = $container['sentry'];
$hpcResolver = $container['hpcResolver'];

$sentry->setTag('Type', 'cli');
$sentry->setTag('Command', 'backfill-hpc');

$query = function () use ($force, $pathPrefixes) {
    $query = Event::query()->without('regions');
    if (!$force) {
        $query->whereNull('footprint_hpc');
    }
    if (!empty($pathPrefixes)) {
        $query->where(function ($q) use ($pathPrefixes) {
            foreach ($pathPrefixes as $prefix) {
                $q->orWhere('path', 'like', $prefix . '%');
            }
        });
    }
    return $query;
};

// Worklist report (also the dry run output)
$counts = $query()
    ->selectRaw("COALESCE(coordinate_system, '(null)') AS cs, COUNT(*) AS n")
    ->groupBy('cs')
    ->orderBy('cs')
    ->pluck('n', 'cs');
$total = $counts->sum();

$scope = ($force ? 'ALL rows (FORCE)' : 'rows with footprint_hpc IS NULL')
       . (!empty($pathPrefixes) ? ', paths: ' . implode(', ', $pathPrefixes) : '');
echo "Backfill worklist ({$scope}): {$total} events\n";
foreach ($counts as $system => $n) {
    echo sprintf("  %-16s %d\n", $system, $n);
}

if (!$apply) {
    echo "\nDry run — nothing written. Re-run with APPLY=1 to backfill.\n";
    exit(0);
}

$logger->info("backfill-hpc | starting | {$total} events | chunk={$chunkSize}");
$startTime = microtime(true);

// Set-based fast path: for helioprojective (and pre-column NULL) rows the
// snapshot is a pure column copy — one UPDATE instead of row-by-row saves.
$copied = $query()
    ->where(function ($q) {
        $q->where('coordinate_system', 'helioprojective')->orWhereNull('coordinate_system');
    })
    ->toBase()
    ->update([
        'x_hpc' => Capsule::raw('hv_hpc_x'),
        'y_hpc' => Capsule::raw('hv_hpc_y'),
        'footprint_hpc' => Capsule::raw('footprint'),
    ]);
if ($copied > 0) {
    $elapsed = round(microtime(true) - $startTime, 1);
    echo "Copied {$copied} helioprojective events set-based in {$elapsed}s.\n";
    $logger->info("backfill-hpc | set-based copy | {$copied} helioprojective events | {$elapsed}s");
}

// Remaining worklist needs the coordinator (stonyhurst / carrington).
$remaining = $total - $copied;
$processed = 0;
$resolved = 0;
$unresolved = 0;

$query()->chunkById($chunkSize, function ($events) use ($hpcResolver, $logger, &$processed, &$resolved, &$unresolved, $remaining, $startTime) {
    $hpcResolver->resolve($events);

    Capsule::connection()->transaction(function () use ($events, &$processed, &$resolved, &$unresolved) {
        foreach ($events as $event) {
            $processed++;
            if ($event->isDirty(['x_hpc', 'y_hpc', 'footprint_hpc'])) {
                $event->timestamps = false;
                $event->save();
                $resolved++;
            } elseif ($event->footprint_hpc === null) {
                $unresolved++;
            }
        }
    });

    $elapsed = round(microtime(true) - $startTime, 1);
    $logger->info("backfill-hpc | {$processed}/{$remaining} | resolved {$resolved} | unresolved {$unresolved} | {$elapsed}s");
});

$duration = round(microtime(true) - $startTime, 1);
$processed += $copied;
$resolved += $copied;
echo "\nBackfill complete in {$duration}s: {$processed} processed, {$resolved} resolved ({$copied} set-based), {$unresolved} unresolved.\n";
if ($unresolved > 0) {
    echo "Unresolved rows keep footprint_hpc = NULL — re-run after checking coordinator availability.\n";
    $logger->warning("backfill-hpc | {$unresolved} events left unresolved");
}
