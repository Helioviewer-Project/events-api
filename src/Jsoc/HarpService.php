<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Jsoc;

use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * HARP Service for querying HARP region data from JSOC.
 * 
 * NOTE: Methods are currently unused but service is maintained for dependency injection.
 * HarpService is injected into DaffProcessor but methods are not called.
 *
 * @package    Helioviewer\EventsApi\JSOC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov> 
 * @since      1.0.0
 */
class HarpService
{
    private JsocClient $jsoc;
    private ?CacheInterface $cache;
    private LoggerInterface $logger;
    private int $cacheTtl = 3600; // 1 hour
    private int $queryDays = 11; // Number of days to query from JSOC

    public function __construct(?ClientInterface $client = null, ?CacheInterface $cache = null, ?LoggerInterface $logger = null)
    {
        $this->jsoc = new JsocClient($client, $logger);
        $this->cache = $cache;
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
    }

    /**
     * UNUSED: Find the closest record before the target datetime from an array of records
     * Not called anywhere in the codebase.
     *
     * @param array $records Array of JSOC records
     * @param string $targetDatetime The target datetime to find closest before
     * @return array|null The closest record or null if none found
     */
    /*
    private function findClosestRecordBefore(array $records, string $targetDatetime): ?array
    {
        $targetTimestamp = strtotime($targetDatetime);
        $closestRecord = null;
        $closestDiff = PHP_INT_MAX;
        
        
        foreach ($records as $record) {
            // Parse HARP time format: 2025.07.02_11:00:00_TAI
            $timeString = str_replace(['_TAI', '_UTC', '_'], ['', '', ' '], $record['time']);
            $timeString = str_replace('.', '-', $timeString);
            $recordTimestamp = strtotime($timeString);
            
            // Only consider records before or at the target time
            if ($recordTimestamp <= $targetTimestamp) {
                $diff = $targetTimestamp - $recordTimestamp;
                if ($diff < $closestDiff) {
                    $closestDiff = $diff;
                    $closestRecord = $record;
                }
            }
        }
        
        return $closestRecord;
    }
    */

    /**
     * UNUSED: Get last known HARP region coordinate data before specified datetime.
     * Not called anywhere in the codebase.
     *
     * @param int $harpNumber The HARP number
     * @param string $datetime The datetime to find coordinates before (Y-m-d H:i:s format)
     * @return array|null HARP record array or null if not found
     */
    /*
    public function getLastCoordinateForHarp(int $harpNumber, string $datetime): ?array
    {
        
        // Extract date for JSOC query - go queryDays earlier
        $date = date('Y-m-d', strtotime($datetime . " -{$this->queryDays} days"));
        
        // Cache key for JSOC records includes date and days
        $cacheKey = "harp_{$harpNumber}_{$date}_{$this->queryDays}d";
        
        $records = null;
        
        // Try to get JSOC records from cache first
        if ($this->cache) {
            try {
                $records = $this->cache->get($cacheKey);
            } catch (\Exception $e) {
            }
        }
        
        // If not in cache, fetch from JSOC
        if ($records === null) {
            try {
                $records = $this->jsoc->fetchByHarpNumberWithDateRange($harpNumber, $date, $this->queryDays);
                
                
                // Cache the JSOC records
                if ($this->cache && $records) {
                    try {
                        $this->cache->set($cacheKey, $records, $this->cacheTtl);
                    } catch (\Exception $e) {
                    }
                }
            } catch (\Exception $e) {
                return null;
            }
        }
        
        if (!$records || empty($records)) {
            return null;
        }
        
        
        // Find the closest record before the specified datetime
        $closestRecord = $this->findClosestRecordBefore($records, $datetime);
        
        if (!$closestRecord) {
            return null;
        }
        
        
        // Transform field names to match DaffProcessor expectations
        $result = [
            'harp_id' => $closestRecord['harp_id'],
            'noaa_id' => $closestRecord['noaa_id'],
            'location_time' => $this->formatJsocTime($closestRecord['time']),
            'latitude' => $closestRecord['lat'],
            'longitude' => $closestRecord['long'],
        ];
        
        
        return $result;
    }
    */

    /**
     * UNUSED: Convert JSOC time format to standard datetime format
     * Only used by commented out methods.
     * Handles formats like: 2013.06.19_12:00:00_TAI
     *
     * @param string $jsocTime Time from JSOC T_REC field
     * @return string Time in Y-m-d H:i:s format
     */
    /*
    private function formatJsocTime(string $jsocTime): string
    {
        // Remove _TAI suffix and replace dots/underscores
        $timeString = str_replace(['_TAI', '_UTC', '_'], ['', '', ' '], $jsocTime);
        $timeString = str_replace('.', '-', $timeString);
        
        // Convert to standard format
        $timestamp = strtotime($timeString);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : $jsocTime;
    }
    */
}