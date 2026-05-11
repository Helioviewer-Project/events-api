<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Jsoc;

use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * NOAA Service for querying NOAA Active Region data from JSOC.
 *
 * @package    Helioviewer\EventsApi\JSOC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov> 
 * @since      1.0.0
 */
class NoaaService
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
     * Find the closest record before the target datetime from an array of records
     *
     * @param array $records Array of JSOC records
     * @param string $targetDatetime The target datetime to find closest before
     * @return array|null The closest record or null if none found
     */
    private function findClosestRecordBefore(array $records, string $targetDatetime): ?array
    {
        $targetTimestamp = strtotime($targetDatetime);
        $closestRecord = null;
        $closestDiff = PHP_INT_MAX;
        
        
        foreach ($records as $record) {
            // Determine which time field to use
            $timeValue = null;
            if (isset($record['ObservationTime'])) {
                // For NOAA Active Regions dataset
                $timeString = str_replace(['_', '.'], [' ', '-'], $record['ObservationTime']);
                $timeString = str_replace('Z', '', $timeString);
                $timeValue = $record['ObservationTime'];
            } elseif (isset($record['time'])) {
                // For HARP logs dataset - format: 2025.07.02_11:00:00_TAI
                $timeString = str_replace(['_TAI', '_UTC', '_'], ['', '', ' '], $record['time']);
                $timeString = str_replace('.', '-', $timeString);
                $timeValue = $record['time'];
            } else {
                continue; // Skip records without time field
            }
            
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

    /**
     * Get last known NOAA region coordinate data before specified datetime.
     *
     * @param int $noaaNumber The NOAA active region number
     * @param string $datetime The datetime to find coordinates before (Y-m-d H:i:s format)
     * @return array|null NOAA record array or null if not found
     */
    public function getLastCoordinateForNoaa(int $noaaNumber, string $datetime): ?array
    {
        
        // Cache key for JSOC records
        $cacheKey = "1bust_noaa_service_{$noaaNumber}";

        $records = null;

        // Try to get JSOC records from cache first
        if ($this->cache) {
            $records = $this->cache->get($cacheKey);
            if ($records !== null) {
                $this->logger->info("NoaaService: cache HIT for NOAA AR {$noaaNumber} (" . count($records) . " record(s)) — JSOC NOT queried");
            }
        }

        // If not in cache, fetch from JSOC
        if ($records === null) {
            $this->logger->info("NoaaService: cache MISS for NOAA AR {$noaaNumber} — querying JSOC");
            $records = $this->jsoc->fetchNoaaActiveRegions($noaaNumber);

            // Cache the JSOC records
            if ($this->cache && $records) {
                $this->cache->set($cacheKey, $records, $this->cacheTtl);
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
        
        
        // Return fields using LongitudeCM for proper heliographic longitude
        $result = [
            'noaa_id' => (string) $closestRecord['RegionNumber'],
            'location_time' => $this->formatJsocTime($closestRecord['ObservationTime']),
            'latitude' => (float) $closestRecord['LatitudeHG'],
            'longitude' => (float) ($closestRecord['LongitudeCM'] ?? 0.0),
        ];
        
        
        return $result;
    }

    /**
     * UNUSED: Get last known NOAA region coordinate data by NOAA Active Region number before specified datetime (from HARP logs).
     * This method is not called anywhere in the codebase.
     *
     * @param int $noaaNumber The NOAA active region number
     * @param string $datetime The datetime to find coordinates before (Y-m-d H:i:s format)
     * @return array|null NOAA record array or null if not found
     */
    /*
    public function getLastCoordinateForNoaaFromHarpLogs(int $noaaNumber, string $datetime): ?array
    {
        
        // Extract date for JSOC query - go queryDays earlier
        $date = date('Y-m-d', strtotime($datetime . " -{$this->queryDays} days"));
        
        // Cache key for JSOC records includes date and days
        $cacheKey = "noaa_hmi_{$noaaNumber}_{$date}_{$this->queryDays}d";
        
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
                $records = $this->jsoc->fetchByNoaaNumber($noaaNumber, $date, $this->queryDays);
                
                
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
            'location_time' => $this->formatJsocTimeHmi($closestRecord['time']),
            'latitude' => $closestRecord['lat'],
            'longitude' => $closestRecord['long'],
        ];
        
        
        return $result;
    }
    */

    /**
     * UNUSED: Convert JSOC HMI time format to standard datetime format
     * Only used by the unused getLastCoordinateForNoaaFromHarpLogs method
     * Handles formats like: 2013.06.19_12:00:00_TAI
     *
     * @param string $jsocTime Time from JSOC T_REC field
     * @return string Time in Y-m-d H:i:s format
     */
    /*
    private function formatJsocTimeHmi(string $jsocTime): string
    {
        // Remove _TAI suffix and replace dots/underscores
        $timeString = str_replace(['_TAI', '_UTC', '_'], ['', '', ' '], $jsocTime);
        $timeString = str_replace('.', '-', $timeString);
        
        // Convert to standard format
        $timestamp = strtotime($timeString);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : $jsocTime;
    }
    */

    /**
     * Convert JSOC time format to standard datetime format
     *
     * @param string $jsocTime Time in format 2025.07.25_00:00:00Z
     * @return string Time in Y-m-d H:i:s format
     */
    private function formatJsocTime(string $jsocTime): string
    {
        $timeString = str_replace(['_', '.'], [' ', '-'], $jsocTime);
        $timeString = str_replace('Z', '', $timeString);
        return date('Y-m-d H:i:s', strtotime($timeString));
    }
}
