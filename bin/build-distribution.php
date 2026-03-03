#!/usr/bin/env php
<?php

declare(strict_types=1);

// Set memory limit for build script
ini_set('memory_limit', '2G');

// === BOOTSTRAP ===
require __DIR__ . '/../src/bootstrap.php';

// === IMPORTS ===
use Helioviewer\EventsApi\Utils\Container;
use Helioviewer\EventsApi\Utils\SignalHandler;

// === SIGNAL HANDLING ===
SignalHandler::setup();

// === SERVICE SETUP ===
$container = Container::getInstance();
$eventRepository = $container['eventRepository'];
$distributionRepository = $container['distributionRepository'];
$logger = $container['logger'];

$logger->info("Starting distribution build");

// === CHECK FOR --truncate FLAG ===
$skipConfirmation = in_array('--truncate', $argv);

// === CONFIRMATION ===
if (!$skipConfirmation) {
    echo "\n";
    echo "WARNING: This will TRUNCATE the distributions table and rebuild from scratch.\n";
    echo "All existing distribution data will be deleted.\n";
    echo "\n";
    echo "Do you want to continue? [y/N]: ";

    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);

    $answer = strtolower(trim($line));
    if ($answer !== 'y' && $answer !== 'yes') {
        $logger->info("Aborted by user");
        echo "Aborted.\n";
        exit(0);
    }
}

// === TRUNCATE FIRST ===
$logger->info("Truncating distributions table...");
$distributionRepository->truncate();

// === PAGINATION SETUP ===
$pageSize = 1000;
$totalEvents = $eventRepository->count();
$totalPages = (int) ceil($totalEvents / $pageSize);

$logger->info("Total events: {$totalEvents}, Pages: {$totalPages}, Page size: {$pageSize}");

// === PROCESS EVENTS ===
$processed = 0;
$startTime = microtime(true);
$lastLogTime = $startTime;

try {
    for ($page = 0; $page < $totalPages; $page++) {
        $pageStart = microtime(true);
        $logger->debug("Fetching page {$page}/{$totalPages}...");
        $events = $eventRepository->getWithPage($page, $pageSize);
        $eventCount = count($events);
        $logger->debug("Fetched {$eventCount} events for page {$page}");

        // Use batch method for bulk insert/update
        $buckets = $distributionRepository->addEventsBatch($events);
        $processed += $eventCount;

        // Log after each page
        $percent = round(($processed / $totalEvents) * 100, 1);
        $memory = round(memory_get_usage(true) / 1024 / 1024, 1);
        $elapsed = round(microtime(true) - $startTime, 1);
        $pageTime = round(microtime(true) - $pageStart, 2);
        $rate = $processed > 0 ? round($processed / max($elapsed, 0.1), 0) : 0;
        $logger->info("Page {$page}/{$totalPages} done - {$processed}/{$totalEvents} ({$percent}%) - {$rate} evt/s - {$buckets} buckets - {$pageTime}s - {$memory}MB");

        // Free memory
        unset($events);
    }
} catch (Throwable $e) {
    $logger->critical("Fatal error at event {$processed}: " . $e->getMessage());
    $logger->debug("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

// === SUMMARY ===
$endTime = microtime(true);
$duration = round($endTime - $startTime, 2);
$avgRate = $processed > 0 ? round($processed / max($duration, 0.1), 2) : 0;
$memory = round(memory_get_peak_usage(true) / 1024 / 1024, 1);

$logger->info("Distribution build completed:");
$logger->info("  - Events processed: {$processed}");
$logger->info("  - Duration: {$duration}s");
$logger->info("  - Rate: {$avgRate} events/sec");
$logger->info("  - Peak memory: {$memory}MB");
