#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Helioviewer\EventsApi\Sources\DonkiCme;
use Helioviewer\EventsApi\Sources\DonkiFlare;
use Helioviewer\EventsApi\Sources\FlareScoreboard;
use Helioviewer\EventsApi\Models\Event;
use Helioviewer\EventsApi\Processors\Database as DatabaseEventProcessor;
use Helioviewer\EventsApi\Processors\Log as LogEventProcessor;
use Helioviewer\EventsApi\Utils\ArgumentParser;
use Carbon\Carbon;

// Initialize log processor for testing/watching without database saves
$eventProcessor = new DatabaseEventProcessor();
// $eventProcessor = new LogEventProcessor();

// FlareScoreboard prediction models (REGIONS only)
$predictionModels = [
    'SIDC_Operator_REGIONS' => 'SIDC Operator',
    'BoM_flare1_REGIONS' => 'Bureau of Meteorology',
    'AMOS_v1_REGIONS' => 'AMOS',
    'ASAP_1_REGIONS' => 'ASAP',
    'MAG4_LOS_FEr_REGIONS' => 'MAG4 LoS FEr',
    'MAG4_LOS_r_REGIONS' => 'MAG4 LoS r',
    'MAG4_SHARP_FE_REGIONS' => 'MAG4 Sharp FE',
    'MAG4_SHARP_REGIONS' => 'MAG4 Sharp',
    'MAG4_SHARP_HMI_REGIONS' => 'MAG4 Sharp HMI',
    'AEffort_REGIONS' => 'AEffort',
    'NOAA_1_REGIONS' => 'NOAA',
    'ASSA_1_REGIONS' => 'ASSA 1',
    'ASSA_24H_1_REGIONS' => 'ASSA 24H',
    'DAFFS_REGIONS' => 'DAFFS',
];

// Initialize sources
$sources = [
    new DonkiCme('CCMC>>DONKI>>CME'),
    new DonkiFlare('CCMC>>DONKI>>Solar Flares'),
];

// Add FlareScoreboard sources
foreach ($predictionModels as $modelId => $modelName) {
    // $sources[] = new FlareScoreboard("CCMC>>Solar Flare Predictions", $modelId, $modelName);
}

// Parse command line arguments for date range
try {
    [$start, $end] = ArgumentParser::parseDateRange($argv);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Collecting events from " . date('Y-m-d H:i:s', $start) . " to " . date('Y-m-d H:i:s', $end) . "\n";

// Collect from each source
foreach ($sources as $index => $source) {
    echo "Collecting from {$source->getPath()}...\n";
    
    try {
        $events = $source->fetch($start, $end);
        echo "Found " . count($events) . " events\n";
        
        // Process Event models using the configured processor
        $eventProcessor->process($events);
        
    } catch (Exception $e) {
        echo "Error collecting from {$source->getPath()}: " . $e->getMessage() . "\n";
    }
    
    // Add random sleep between sources (1-2 seconds), except for the last source
    if ($index < count($sources) - 1) {
        $sleepTime = rand(1000000, 2000000); // microseconds (1-2 seconds)
        echo "Sleeping for " . ($sleepTime / 1000000) . " seconds...\n";
        usleep($sleepTime);
    }
}

echo "Collection complete\n";
