#!/usr/bin/env php
<?php

declare(strict_types=1);

// === BOOTSTRAP ===
require __DIR__ . '/../src/bootstrap.php';

// === IMPORTS ===
use Helioviewer\EventsApi\Utils\Container;
use Helioviewer\EventsApi\Regions\Region;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Illuminate\Database\Capsule\Manager as DB;

$container = Container::getInstance();

// Parse arguments
$dryRun = true;
foreach ($argv as $arg) {
    if ($arg === '--fix' || $arg === 'apply') {
        $dryRun = false;
    }
}

if ($dryRun) {
    echo "=== DRY RUN MODE ===\n";
    echo "Run with 'apply' to apply changes\n\n";
} else {
    echo "=== APPLYING FIXES ===\n\n";
}

// Statistics
$stats = [
    'ccmc_only_fixed' => 0,
    'both_fixed' => 0,
    'regions_created' => 0,
    'regions_deleted' => 0,
    'events_moved' => 0,
    'skipped_rhessi_only' => 0,
    'skipped_correct' => 0,
    'errors' => 0,
];

try {
    // Get all NOAA regions with numeric external_id only
    $regions = Region::where('organization', 'NOAA')
        ->whereRaw("external_id ~ '^[0-9]+$'")
        ->orderByRaw('CAST(external_id AS INTEGER)')
        ->get();

    if ($regions->isEmpty()) {
        echo "No NOAA regions with numeric IDs found in the database.\n";
        exit(0);
    }

    echo "Total NOAA Regions (numeric IDs): " . $regions->count() . "\n\n";

    foreach ($regions as $region) {
        $externalId = (int) $region->external_id;

        // Skip regions that are already correct (>= 9000)
        if ($externalId >= 9000) {
            $stats['skipped_correct']++;
            continue;
        }

        // Get events by source
        $ccmcEvents = $region->events()->where('source_id', JsonSource::CCMC)->count();
        $rhessiEvents = $region->events()->where('source_id', JsonSource::RHESSI)->count();

        // Determine usage
        if ($ccmcEvents > 0 && $rhessiEvents > 0) {
            $usedBy = 'BOTH';
        } elseif ($ccmcEvents > 0) {
            $usedBy = 'CCMC';
        } elseif ($rhessiEvents > 0) {
            $usedBy = 'RHESSI';
            $stats['skipped_rhessi_only']++;
            continue; // RHESSI-only regions are fine
        } else {
            continue; // No events, skip
        }

        $targetExternalId = $externalId + 10000;
        echo "Region {$externalId} ({$usedBy}): CCMC={$ccmcEvents}, RHESSI={$rhessiEvents}\n";
        echo "  -> Target region: {$targetExternalId}\n";

        if ($dryRun) {
            // Dry run - just report what would happen
            $targetRegion = Region::where('organization', 'NOAA')
                ->where('external_id', (string) $targetExternalId)
                ->first();

            if ($targetRegion) {
                $targetCcmcEvents = $targetRegion->events()->where('source_id', JsonSource::CCMC)->count();
                echo "  -> Target exists with {$targetCcmcEvents} CCMC events (will merge)\n";
            } else {
                echo "  -> Target does not exist (will create)\n";
            }

            if ($usedBy === 'CCMC') {
                echo "  -> Would move {$ccmcEvents} CCMC events and delete region {$externalId}\n";
                $stats['ccmc_only_fixed']++;
                $stats['events_moved'] += $ccmcEvents;
            } else { // BOTH
                echo "  -> Would move {$ccmcEvents} CCMC events, keep region {$externalId} for {$rhessiEvents} RHESSI events\n";
                $stats['both_fixed']++;
                $stats['events_moved'] += $ccmcEvents;
            }
        } else {
            // Actually apply the fix
            try {
                DB::beginTransaction();

                // Find or create target region
                $targetRegion = Region::where('organization', 'NOAA')
                    ->where('external_id', (string) $targetExternalId)
                    ->first();

                if (!$targetRegion) {
                    $targetRegion = Region::create([
                        'organization' => 'NOAA',
                        'external_id' => (string) $targetExternalId,
                    ]);
                    echo "  -> Created target region {$targetExternalId}\n";
                    $stats['regions_created']++;
                } else {
                    echo "  -> Found existing target region {$targetExternalId}\n";
                }

                // Get CCMC event IDs for this region
                $ccmcEventIds = $region->events()
                    ->where('source_id', JsonSource::CCMC)
                    ->pluck('events.id')
                    ->toArray();

                if (!empty($ccmcEventIds)) {
                    // Move CCMC events to target region
                    // First, attach to new region
                    $targetRegion->events()->syncWithoutDetaching($ccmcEventIds);

                    // Then, detach from old region
                    $region->events()->detach($ccmcEventIds);

                    echo "  -> Moved " . count($ccmcEventIds) . " CCMC events to region {$targetExternalId}\n";
                    $stats['events_moved'] += count($ccmcEventIds);
                }

                if ($usedBy === 'CCMC') {
                    // CCMC-only: verify and delete old region
                    $remainingEvents = $region->events()->count();

                    if ($remainingEvents > 0) {
                        throw new Exception("Region {$externalId} still has {$remainingEvents} events after move!");
                    }

                    $region->delete();
                    echo "  -> Deleted region {$externalId}\n";
                    $stats['regions_deleted']++;
                    $stats['ccmc_only_fixed']++;
                } else {
                    // BOTH: keep region for RHESSI events
                    echo "  -> Kept region {$externalId} for {$rhessiEvents} RHESSI events\n";
                    $stats['both_fixed']++;
                }

                DB::commit();

            } catch (Exception $e) {
                DB::rollBack();
                echo "  -> ERROR: " . $e->getMessage() . "\n";
                $stats['errors']++;
            }
        }

        echo "\n";
    }

    // Print summary
    echo "=== SUMMARY ===\n";
    if ($dryRun) {
        echo "(Dry run - no changes made)\n";
    }
    echo "CCMC-only regions fixed: {$stats['ccmc_only_fixed']}\n";
    echo "BOTH regions fixed: {$stats['both_fixed']}\n";
    echo "Regions created: {$stats['regions_created']}\n";
    echo "Regions deleted: {$stats['regions_deleted']}\n";
    echo "Events moved: {$stats['events_moved']}\n";
    echo "Skipped (RHESSI-only): {$stats['skipped_rhessi_only']}\n";
    echo "Skipped (already correct): {$stats['skipped_correct']}\n";
    if ($stats['errors'] > 0) {
        echo "Errors: {$stats['errors']}\n";
    }

} catch (Exception $e) {
    $container['logger']->error("Fix regions failed: " . $e->getMessage());
    $container['logger']->debug("Stack trace: " . $e->getTraceAsString());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
