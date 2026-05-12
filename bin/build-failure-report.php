<?php

declare(strict_types=1);

/**
 * Build a single aggregated JSON report from every failure file on disk.
 *
 * Output: /u/apps/data/failures-report.json
 *
 * Read by the FailuresController and /failures UI so the page doesn't have
 * to scan ~1k+ files on every request. Run periodically (every 2 hours via
 * bin/scheduler.php) or on demand with `make build-failure-report`.
 */

ini_set('memory_limit', '512M');

require __DIR__ . '/../src/bootstrap.php';

use Helioviewer\EventsApi\Utils\Container;
use Helioviewer\EventsApi\Utils\SignalHandler;

SignalHandler::setup();

const FAILURES_ROOT = '/u/apps/data/failures';
const REPORT_PATH   = '/u/apps/data/failures-report.json';

$container = Container::getInstance();
$logger = $container['logger'];

$start = microtime(true);
$logger->info("Building failures report from " . FAILURES_ROOT);

$items             = [];
$byType            = [];
$bySource          = [];
$bySourceFamily    = [];
$byDisplaySource   = [];
$byException       = [];
$byErrorCount      = [];
$byErrorSample     = [];

if (is_dir(FAILURES_ROOT)) {
    foreach ((scandir(FAILURES_ROOT) ?: []) as $type) {
        if ($type === '.' || $type === '..') continue;
        $typeDir = FAILURES_ROOT . '/' . $type;
        if (!is_dir($typeDir)) continue;

        foreach ((scandir($typeDir) ?: []) as $source) {
            if ($source === '.' || $source === '..') continue;
            $srcDir = $typeDir . '/' . $source;
            if (!is_dir($srcDir)) continue;

            $family = source_family($source);

            foreach (glob($srcDir . '/*.json') ?: [] as $file) {
                $raw = @file_get_contents($file);
                if ($raw === false || $raw === '') continue;
                $data = json_decode($raw, true);
                if (!is_array($data)) continue;

                $basename  = basename($file);
                $ts        = (int)($data['timestamp'] ?? @filemtime($file) ?: 0);
                $errorText = trim((string)($data['error'] ?? ''));
                $errorKey  = error_cluster_key($errorText);
                $exClass   = $data['exception_class'] ?? null;
                $exShort   = $exClass ? short_class_name($exClass) : null;

                $items[] = [
                    'id'              => base64_encode("$type/$source/$basename"),
                    'type'            => $type,
                    'source'          => $source,
                    'source_family'   => $family,
                    'display_source'  => $family . '>>' . $source,
                    'filename'        => $basename,
                    'timestamp'       => $ts,
                    'datetime'        => $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null,
                    'exception_class' => $exClass,
                    'exception_short' => $exShort,
                    'error'           => $errorText,
                    'error_cluster'   => $errorKey,
                    'raw_record'      => $data['raw_record'] ?? null,
                    'static_url'      => "/static/failures/$type/$source/$basename",
                ];

                $displaySource = $family . '>>' . $source;

                $byType[$type]                 = ($byType[$type] ?? 0) + 1;
                $bySource[$source]             = ($bySource[$source] ?? 0) + 1;
                $bySourceFamily[$family]       = ($bySourceFamily[$family] ?? 0) + 1;
                $byDisplaySource[$displaySource] = ($byDisplaySource[$displaySource] ?? 0) + 1;
                if ($exShort) {
                    $byException[$exShort]     = ($byException[$exShort] ?? 0) + 1;
                }
                $byErrorCount[$errorKey]       = ($byErrorCount[$errorKey] ?? 0) + 1;
                if (!isset($byErrorSample[$errorKey])) {
                    $byErrorSample[$errorKey] = $errorText;
                }
            }
        }
    }
}

// Newest first
usort($items, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

ksort($byType);
ksort($bySource);
ksort($bySourceFamily);
ksort($byDisplaySource);
ksort($byException);
arsort($byErrorCount);

$errorGroups = [];
foreach ($byErrorCount as $key => $count) {
    $errorGroups[] = [
        'key'    => $key,
        'count'  => $count,
        'sample' => $byErrorSample[$key],
    ];
}

$report = [
    'generated_at'     => gmdate('Y-m-d\TH:i:s\Z'),
    'total'            => count($items),
    'by_type'          => empty($byType)         ? new stdClass() : $byType,
    'by_source'        => empty($bySource)       ? new stdClass() : $bySource,
    'by_source_family'  => empty($bySourceFamily)  ? new stdClass() : $bySourceFamily,
    'by_display_source' => empty($byDisplaySource) ? new stdClass() : $byDisplaySource,
    'by_exception'      => empty($byException)     ? new stdClass() : $byException,
    'error_groups'     => $errorGroups,
    'items'            => $items,
];

$json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) {
    $logger->critical("json_encode failed: " . json_last_error_msg());
    exit(1);
}

// Atomic write: temp file then rename
$tmp = REPORT_PATH . '.tmp.' . posix_getpid();
if (file_put_contents($tmp, $json) === false) {
    $logger->critical("Failed to write temp report at {$tmp}");
    exit(1);
}
if (!rename($tmp, REPORT_PATH)) {
    @unlink($tmp);
    $logger->critical("Failed to atomically rename temp report to " . REPORT_PATH);
    exit(1);
}

$dur = round(microtime(true) - $start, 2);
$mb  = round(filesize(REPORT_PATH) / 1024 / 1024, 2);
$logger->info("Failures report built: " . count($items) . " failures, {$mb}MB, {$dur}s -> " . REPORT_PATH);

/**
 * Group similar failure sources under a higher-level family
 * (e.g. DONKI_CME, DONKI_FLARE, FLARE_SCOREBOARD_* -> CCMC).
 */
function source_family(string $source): string
{
    if (str_starts_with($source, 'DONKI_'))           return 'CCMC';
    if (str_starts_with($source, 'FLARE_SCOREBOARD_')) return 'CCMC';
    if (str_starts_with($source, 'HEK'))              return 'HEK';
    if (str_starts_with($source, 'RHESSI'))           return 'RHESSI';
    return 'Other';
}

/**
 * Strip the namespace from a fully-qualified class name.
 * "Helioviewer\EventsApi\Exception\CoordinateResolutionException" -> "CoordinateResolutionException"
 */
function short_class_name(string $fqcn): string
{
    $pos = strrpos($fqcn, '\\');
    return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
}

/**
 * Normalize an error message so similar errors (only differing by
 * numbers / UUIDs / timestamps) cluster together. Truncated to keep
 * the key small.
 */
function error_cluster_key(string $text): string
{
    if ($text === '') return '(empty)';
    // Collapse runs of digits to N so "Region 1234" and "Region 5678" cluster
    $key = preg_replace('/\d+/', 'N', $text) ?? $text;
    return mb_substr($key, 0, 160);
}
