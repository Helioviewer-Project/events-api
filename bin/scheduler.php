#!/usr/bin/env php
<?php

declare(strict_types=1);

// === BOOTSTRAP ===
require __DIR__ . '/../src/bootstrap.php';

use GO\Scheduler;
use Helioviewer\EventsApi\Utils\Container;
use Helioviewer\EventsApi\Utils\SignalHandler;
use Helioviewer\EventsApi\Events\Collector as EventCollector;
use Helioviewer\EventsApi\Utils\TimeRange;

// === SIGNAL HANDLING ===
SignalHandler::setup();

$container = Container::getInstance();
$logger = $container['logger'];
$sentry = $container['sentry'];

$sentry->setTag('Type', 'scheduler');

// Get process user and group information
$uid = posix_getuid();
$gid = posix_getgid();
$userInfo = posix_getpwuid($uid);
$groupInfo = posix_getgrgid($gid);

$userDisplay = $userInfo['name'] ?? $uid;
$groupDisplay = $groupInfo['name'] ?? $gid;

$logger->info("Starting Helioviewer Events API Scheduler Daemon (User: {$userDisplay}, Group: {$groupDisplay})");

// Create EventCollector instance using standard factory
$eventCollector = EventCollector::createStandard(
    $container['eventRepository'],
    $container['regionRepository'],
    $container['distributionRepository'],
    $container['jsonStorage'],
    $container['failureStorage'],
    $container['httpClient'],
    $container['harp'],
    $container['noaa'],
    $logger,
    $sentry,
    $container['hpcResolver'],
    $container['cache']
);

// Create scheduler with lock directory
$scheduler = new Scheduler([
    'tempDir' => '/u/apps/data/scheduler'
]);

// Create temp directory if it doesn't exist
if (!is_dir('/u/apps/data/scheduler')) {
    mkdir('/u/apps/data/scheduler', 0777, true);
}

// Schedule collection job (every 6 minutes) - collects today's events
$scheduler->call(function() use ($eventCollector, $logger, $sentry) {
    $sentry->withTransaction('scheduler.every_6_minutes', 'cron', function() use ($eventCollector, $logger, $sentry) {
        // Collect today
        $start = strtotime('today');
        $end = strtotime('tomorrow') - 1;
        $timeRange = TimeRange::fromTimestamps($start, $end);

        $logger->info("[EVERY 6 MINUTES] Starting event collection for " . date('Y-m-d', $start));

        $startTime = microtime(true);

        try {
            // Collect from all sources (returns event count)
            $totalEvents = $eventCollector->collect($timeRange);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            // Show summary
            $avgRate = round($totalEvents / max($duration, 0.1), 2);
            $logger->info("[EVERY 6 MINUTES] Collection completed in {$duration}s with total {$totalEvents} events, average {$avgRate} events/sec");

        } catch (\Throwable $e) {
            $sentry->setTag('Job', 'every_6_minutes_collection');
            $sentry->capture($e);
            $logger->critical("[EVERY 6 MINUTES] Collection failed: " . $e->getMessage());
            $logger->debug("[EVERY 6 MINUTES] Stack trace: " . $e->getTraceAsString());
            throw new \RuntimeException("[EVERY 6 MINUTES] Scheduler failed: " . get_class($e) . " - " . $e->getMessage(), 0, $e);
        }
    });
}, [], 'every_6_minutes_collection')
    ->everyMinute(6)                      // Run every 6 minutes
    ->onlyOne()  // Prevent overlapping with explicit lock directory
    ->before(function() use ($logger) {
        $logger->info("[EVERY 6 MINUTES] Starting scheduled collection");
    })
    ->then(function($output) use ($logger) {
        $logger->info("[EVERY 6 MINUTES] Scheduled collection completed");
    });

// Daily full collection at 2 AM - collects yesterday and today
$scheduler->call(function() use ($eventCollector, $logger, $sentry) {
    $sentry->withTransaction('scheduler.daily_2am', 'cron', function() use ($eventCollector, $logger, $sentry) {
        // Collect yesterday and today
        $start = strtotime('yesterday');
        $end = strtotime('tomorrow') - 1;
        $timeRange = TimeRange::fromTimestamps($start, $end);

        $logger->info("[DAILY 2AM] Starting event collection for " . date('Y-m-d', $start) . " to " . date('Y-m-d', $end));

        $startTime = microtime(true);

        try {
            // Collect from all sources (2 days, returns event count)
            $totalEvents = $eventCollector->collect($timeRange);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            // Show summary
            $avgRate = round($totalEvents / max($duration, 0.1), 2);
            $logger->info("[DAILY 2AM] Collection completed in {$duration}s with total {$totalEvents} events, average {$avgRate} events/sec");

        } catch (\Throwable $e) {
            $sentry->setTag('Job', 'daily_previous_day_2am_collection');
            $sentry->capture($e);
            $logger->critical("[DAILY 2AM] Collection failed: " . $e->getMessage());
            $logger->debug("[DAILY 2AM] Stack trace: " . $e->getTraceAsString());
            throw new \RuntimeException("[DAILY 2AM] Scheduler failed: " . get_class($e) . " - " . $e->getMessage(), 0, $e);
        }
    });
}, [], 'daily_previous_day_2am_collection')
    ->daily('02:00')  // Run at 02:00 (2 AM)
    ->onlyOne()
    ->before(function() use ($logger) {
        $logger->info("[DAILY 2AM] Starting daily collection for yesterday and today");
    })
    ->then(function($output) use ($logger) {
        $logger->info("[DAILY 2AM] Daily collection completed");
    });

// Weekly full collection at Monday 01:00 UTC - sweeps the just-completed Mon→Sun week
$scheduler->call(function() use ($eventCollector, $logger, $sentry) {
    $sentry->withTransaction('scheduler.weekly_monday_1am_utc', 'cron', function() use ($eventCollector, $logger, $sentry) {
        $now = \Carbon\CarbonImmutable::now('UTC');
        $lastWeek = $now->subWeek();
        $start = $lastWeek->startOfWeek(\Carbon\Carbon::MONDAY);
        $end = $lastWeek->endOfWeek(\Carbon\Carbon::SUNDAY);

        $timeRange = TimeRange::fromTimestamps($start->timestamp, $end->timestamp);

        $logger->info("[WEEKLY MON 1AM UTC] Starting event collection for "
            . $start->toIso8601String() . " to " . $end->toIso8601String());

        $startTime = microtime(true);

        try {
            $totalEvents = $eventCollector->collect($timeRange);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $avgRate = round($totalEvents / max($duration, 0.1), 2);
            $logger->info("[WEEKLY MON 1AM UTC] Collection completed in {$duration}s with total {$totalEvents} events, average {$avgRate} events/sec");

        } catch (\Throwable $e) {
            $sentry->setTag('Job', 'weekly_monday_1am_utc_collection');
            $sentry->capture($e);
            $logger->critical("[WEEKLY MON 1AM UTC] Collection failed: " . $e->getMessage());
            $logger->debug("[WEEKLY MON 1AM UTC] Stack trace: " . $e->getTraceAsString());
            throw new \RuntimeException("[WEEKLY MON 1AM UTC] Scheduler failed: " . get_class($e) . " - " . $e->getMessage(), 0, $e);
        }
    });
}, [], 'weekly_monday_1am_utc_collection')
    ->weekly(1, 1, 0)  // weekday 1 = Monday, 01:00
    ->onlyOne()
    ->before(function() use ($logger) {
        $logger->info("[WEEKLY MON 1AM UTC] Starting scheduled collection");
    })
    ->then(function($output) use ($logger) {
        $logger->info("[WEEKLY MON 1AM UTC] Scheduled collection completed");
    });

// Monthly full collection at 1st of month 03:00 UTC - sweeps the just-completed calendar month
$scheduler->call(function() use ($eventCollector, $logger, $sentry) {
    $sentry->withTransaction('scheduler.monthly_first_3am_utc', 'cron', function() use ($eventCollector, $logger, $sentry) {
        $now = \Carbon\CarbonImmutable::now('UTC');
        $lastMonth = $now->subMonth();
        $start = $lastMonth->startOfMonth();
        $end = $lastMonth->endOfMonth();

        $timeRange = TimeRange::fromTimestamps($start->timestamp, $end->timestamp);

        $logger->info("[MONTHLY 1ST 3AM UTC] Starting event collection for "
            . $start->toIso8601String() . " to " . $end->toIso8601String());

        $startTime = microtime(true);

        try {
            $totalEvents = $eventCollector->collect($timeRange);

            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $avgRate = round($totalEvents / max($duration, 0.1), 2);
            $logger->info("[MONTHLY 1ST 3AM UTC] Collection completed in {$duration}s with total {$totalEvents} events, average {$avgRate} events/sec");

        } catch (\Throwable $e) {
            $sentry->setTag('Job', 'monthly_first_3am_utc_collection');
            $sentry->capture($e);
            $logger->critical("[MONTHLY 1ST 3AM UTC] Collection failed: " . $e->getMessage());
            $logger->debug("[MONTHLY 1ST 3AM UTC] Stack trace: " . $e->getTraceAsString());
            throw new \RuntimeException("[MONTHLY 1ST 3AM UTC] Scheduler failed: " . get_class($e) . " - " . $e->getMessage(), 0, $e);
        }
    });
}, [], 'monthly_first_3am_utc_collection')
    ->monthly('*', 1, 3, 0)  // every month, day 1, 03:00
    ->onlyOne()
    ->before(function() use ($logger) {
        $logger->info("[MONTHLY 1ST 3AM UTC] Starting scheduled collection");
    })
    ->then(function($output) use ($logger) {
        $logger->info("[MONTHLY 1ST 3AM UTC] Scheduled collection completed");
    });

// Build aggregated failures report every 2 hours so /failures stays current
// without scanning ~1k+ files on every page load.
$scheduler->call(function() use ($logger, $sentry) {
    $sentry->withTransaction('scheduler.failure_report_every_2h', 'cron', function() use ($logger, $sentry) {
        $logger->info("[FAILURE REPORT 2H] Building failure report");
        $startTime = microtime(true);

        try {
            $cmd = escapeshellcmd('/usr/local/bin/php') . ' ' . escapeshellarg(__DIR__ . '/build-failure-report.php');
            $output = [];
            $code = 0;
            exec($cmd . ' 2>&1', $output, $code);
            $duration = round(microtime(true) - $startTime, 2);

            if ($code !== 0) {
                $msg = "[FAILURE REPORT 2H] Build failed (exit {$code}) in {$duration}s: " . implode("\n", array_slice($output, -10));
                $logger->critical($msg);
                $sentry->setTag('Job', 'failure_report_every_2h');
                $sentry->message($msg);
                return;
            }
            $logger->info("[FAILURE REPORT 2H] Build completed in {$duration}s");
        } catch (\Throwable $e) {
            $sentry->setTag('Job', 'failure_report_every_2h');
            $sentry->capture($e);
            $logger->critical("[FAILURE REPORT 2H] Build crashed: " . $e->getMessage());
            $logger->debug("[FAILURE REPORT 2H] Stack: " . $e->getTraceAsString());
        }
    });
}, [], 'failure_report_every_2h')
    ->at('5 */2 * * *')  // every 2 hours at minute 5 (offset from on-the-hour collection jobs)
    ->onlyOne()
    ->before(function() use ($logger) {
        $logger->info("[FAILURE REPORT 2H] Starting scheduled rebuild");
    })
    ->then(function() use ($logger) {
        $logger->info("[FAILURE REPORT 2H] Scheduled rebuild completed");
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

// Exit successfully - Docker will run this again in 360 seconds (6 minutes)
exit(0);
