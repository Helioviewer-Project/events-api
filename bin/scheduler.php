#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// === CONTAINER SETUP ===
$container = require __DIR__ . '/../src/container.php';

use GO\Scheduler;
use Helioviewer\EventsApi\Utils\SignalHandler;
use Helioviewer\EventsApi\Events\Collector as EventCollector;
use Helioviewer\EventsApi\Utils\TimeRange;

// === SIGNAL HANDLING ===
SignalHandler::setup();

$logger = $container['logger'];

$logger->info("Starting Helioviewer Events API Scheduler Daemon");

// Create EventCollector instance using standard factory
$eventCollector = EventCollector::createStandard(
    $container['eventRepository'],
    $container['regionRepository'],
    $container['jsonStorage'],
    $container['failureStorage'],
    $container['httpClient'],
    $container['harp'],
    $container['noaa'],
    $logger
);

// Create scheduler with lock directory
$scheduler = new Scheduler([
    'tempDir' => '/u/apps/data/scheduler'
]);

// Create temp directory if it doesn't exist
if (!is_dir('/u/apps/data/scheduler')) {
    mkdir('/u/apps/data/scheduler', 0777, true);
}

// Schedule collection job (every minute) - collects today's events
$scheduler->call(function() use ($eventCollector, $logger) {
    // Collect today
    $start = strtotime('today');
    $end = strtotime('tomorrow') - 1;
    $timeRange = TimeRange::fromTimestamps($start, $end);
    
    $logger->info("[EVERY MINUTE] Starting event collection for " . date('Y-m-d', $start));
    
    $startTime = microtime(true);
    
    try {
        // Collect from all sources
        $events = $eventCollector->collect($timeRange);
        $totalEvents = count($events);
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        // Show summary
        $avgRate = round($totalEvents / max($duration, 0.1), 2);
        $logger->info("[EVERY MINUTE] Collection completed with total {$totalEvents} events, average {$avgRate} events/sec");
        
    } catch (\Throwable $e) {
        $logger->critical("[EVERY MINUTE] Collection failed: " . $e->getMessage());
        $logger->debug("[EVERY MINUTE] Stack trace: " . $e->getTraceAsString());
        throw new \RuntimeException("[EVERY MINUTE] Scheduler failed: " . get_class($e) . " - " . $e->getMessage(), 0, $e);
    }
}, [], 'every_minute_collection')
    ->everyMinute()                       // Run every minute
    ->onlyOne()  // Prevent overlapping with explicit lock directory
    ->before(function() use ($logger) {
        $logger->info("[EVERY MINUTE] Starting scheduled collection");
    })
    ->then(function($output) use ($logger) {
        $logger->info("[EVERY MINUTE] Scheduled collection completed");
    });

// Daily full collection at 2 AM - collects yesterday and today
$scheduler->call(function() use ($eventCollector, $logger) {
    // Collect yesterday and today
    $start = strtotime('yesterday');
    $end = strtotime('tomorrow') - 1;
    $timeRange = TimeRange::fromTimestamps($start, $end);
    
    $logger->info("[DAILY 2AM] Starting event collection for " . date('Y-m-d', $start) . " to " . date('Y-m-d', $end));
    
    $startTime = microtime(true);
    
    try {
        // Collect from all sources (2 days)
        $events = $eventCollector->collect($timeRange);
        $totalEvents = count($events);
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        // Show summary
        $avgRate = round($totalEvents / max($duration, 0.1), 2);
        $logger->info("[DAILY 2AM] Collection completed with total {$totalEvents} events, average {$avgRate} events/sec");
        
    } catch (\Throwable $e) {
        $logger->critical("[DAILY 2AM] Collection failed: " . $e->getMessage());
        $logger->debug("[DAILY 2AM] Stack trace: " . $e->getTraceAsString());
        throw new \RuntimeException("[DAILY 2AM] Scheduler failed: " . get_class($e) . " - " . $e->getMessage(), 0, $e);
    }
}, [], 'daily_previous_day_2am_collection')
    ->daily('02:00')  // Run at 02:00 (2 AM)
    ->onlyOne()
    ->before(function() use ($logger) {
        $logger->info("[DAILY 2AM] Starting daily collection for yesterday and today");
    })
    ->then(function($output) use ($logger) {
        $logger->info("[DAILY 2AM] Daily collection completed");
    });

$logger->info("Scheduler run started at " . date('Y-m-d H:i:s'));

// Check if scheduler is enabled via environment variable
$schedulerEnabled = ($_ENV['SCHEDULER_ENABLED'] ?? 'true') === 'true';

if (!$schedulerEnabled) {
    $logger->info("Scheduler is disabled via SCHEDULER_ENABLED environment variable");
    exit(0);
}

try {

    // Run the scheduler - it will check which jobs are due and execute them
    $scheduler->run();
    
    // Check for failed jobs
    $failedJobs = $scheduler->getFailedJobs();
    
    if (!empty($failedJobs)) {
        $logger->error("Some jobs failed during execution");
        
        foreach ($failedJobs as $failedJob) {
            $exception = $failedJob->getException();
            $job = $failedJob->getJob();
            
            // Log failed job details with concatenation
            $logger->error("Failed job: " . $job->getId() . " | Error: " . $exception->getMessage());
            
            // Clean up lock file for failed job
            $jobId = $job->getId();
            if ($jobId) {
                $lockFile = '/u/apps/data/scheduler/' . $jobId . '.lock';
                if (file_exists($lockFile)) {
                    unlink($lockFile);
                    $logger->info("Removed lock file for failed job: {$jobId}");
                }
            }
        }
        
        // Still log completion but note there were failures
        $memoryMb = round(memory_get_usage(true) / 1024 / 1024, 1);
        $peakMemoryMb = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
        $logger->error("Scheduler run completed with failures - Memory: {$memoryMb}MB (peak: {$peakMemoryMb}MB)");
    } else {
        // Log successful execution
        $memoryMb = round(memory_get_usage(true) / 1024 / 1024, 1);
        $peakMemoryMb = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
        $logger->info("Scheduler run completed successfully - Memory: {$memoryMb}MB (peak: {$peakMemoryMb}MB)");
    }
    
} catch (\Throwable $e) {
    $logger->critical("Scheduler error: " . $e->getMessage());
    $logger->critical("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

// Exit successfully - Docker will run this again in 60 seconds
exit(0);
