#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * List every source the collector knows about, with the path its events land
 * under. The names are what `make collect SOURCES="..."` expects.
 *
 * Usage:
 *   php bin/sources.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Helioviewer\EventsApi\Events\Collector as EventCollector;
use Helioviewer\EventsApi\Utils\Container;

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
    $container['sentry'],
    $container['hpcResolver'],
    $container['cache']
);

$rows = [];
foreach ($collector->getSources() as $path => $source) {
    $rows[] = [$source->getName(), $path];
}

$width = max(array_map(fn($row) => strlen($row[0]), $rows) ?: [6]);

printf("%-{$width}s  %s\n", 'SOURCE', 'PATH');
printf("%-{$width}s  %s\n", str_repeat('-', $width), str_repeat('-', 40));
foreach ($rows as [$name, $path]) {
    printf("%-{$width}s  %s\n", $name, $path);
}

echo "\n" . count($rows) . " sources registered.\n";
echo "Collect a subset with: make collect SOURCES=\"" . $rows[0][0] . "\" [dates]\n";
