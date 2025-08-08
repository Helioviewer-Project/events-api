<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\JSOC;

use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * HARP Service for querying HARP region data from JSOC.
 *
 * @package    Helioviewer\EventsApi\JSOC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov> 
 * @since      1.0.0
 */
class HarpService
{
    private JSOC $jsoc;
    private ?CacheInterface $cache;
    private int $cacheTtl = 3600; // 1 hour
    private int $queryDays = 11; // Number of days to query from JSOC

    public function __construct(?ClientInterface $client = null, ?CacheInterface $cache = null)
    {
        $this->jsoc = new JSOC($client);
        $this->cache = $cache;
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
        
        // Debug first few records to see time values
        echo "DEBUG: HarpService checking first 3 records:\n";
        for ($i = 0; $i < min(3, count($records)); $i++) {
            echo "  Record[$i] time: {$records[$i]['time']}\n";
        }
        
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

    /**
     * Get last known HARP region coordinate data before specified datetime.
     *
     * @param int $harpNumber The HARP number
     * @param string $datetime The datetime to find coordinates before (Y-m-d H:i:s format)
     * @return array|null HARP record array or null if not found
     */
    public function getLastCoordinateForHarp(int $harpNumber, string $datetime): ?array
    {
        echo "DEBUG: HarpService looking for HARP {$harpNumber} before {$datetime}\n";
        
        // Extract date for JSOC query - go queryDays earlier
        $date = date('Y-m-d', strtotime($datetime . " -{$this->queryDays} days"));
        
        // Cache key for JSOC records includes date and days
        $cacheKey = "harp_{$harpNumber}_{$date}_{$this->queryDays}d";
        
        $records = null;
        
        // Try to get JSOC records from cache first
        if ($this->cache) {
            try {
                $records = $this->cache->get($cacheKey);
                if ($records !== null) {
                    echo "DEBUG: HarpService cache HIT for HARP {$harpNumber} - found " . count($records) . " records\n";
                    if (!empty($records)) {
                        echo "\n[CACHE SAMPLE ROW]:\n";
                        $sample = $records[0];
                        foreach ($sample as $key => $value) {
                            echo "  {$key} => " . json_encode($value) . "\n";
                        }
                        echo "\n";
                    }
                } else {
                    echo "DEBUG: HarpService cache MISS for HARP {$harpNumber}\n";
                }
            } catch (\Exception $e) {
                echo "DEBUG: HarpService cache read error for HARP {$harpNumber}: " . $e->getMessage() . "\n";
                error_log("Cache read error for HARP {$harpNumber}: " . $e->getMessage());
            }
        }
        
        // If not in cache, fetch from JSOC
        if ($records === null) {
            echo "DEBUG: HarpService fetching HARP {$harpNumber} from JSOC ({$date}, {$this->queryDays} days)...\n";
            try {
                $records = $this->jsoc->fetchByHarpNumberWithDateRange($harpNumber, $date, $this->queryDays);
                
                if ($records) {
                    echo "DEBUG: HarpService fetched " . count($records) . " records from JSOC for HARP {$harpNumber}\n";
                    if (!empty($records)) {
                        echo "\n[JSOC SAMPLE ROW]:\n";
                        $sample = $records[0];
                        foreach ($sample as $key => $value) {
                            echo "  {$key} => " . json_encode($value) . "\n";
                        }
                        echo "\n";
                    }
                } else {
                    echo "DEBUG: HarpService no records found in JSOC for HARP {$harpNumber}\n";
                }
                
                // Cache the JSOC records
                if ($this->cache && $records) {
                    try {
                        $this->cache->set($cacheKey, $records, $this->cacheTtl);
                        echo "DEBUG: HarpService cached " . count($records) . " records for HARP {$harpNumber}\n";
                    } catch (\Exception $e) {
                        echo "DEBUG: HarpService cache write error for HARP {$harpNumber}: " . $e->getMessage() . "\n";
                        error_log("Cache write error for HARP {$harpNumber}: " . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                echo "DEBUG: HarpService failed to fetch HARP {$harpNumber} from JSOC: " . $e->getMessage() . "\n";
                error_log("Failed to fetch HARP {$harpNumber} from JSOC: " . $e->getMessage());
                return null;
            }
        }
        
        if (!$records || empty($records)) {
            echo "DEBUG: HarpService no records available for HARP {$harpNumber}\n";
            return null;
        }
        
        echo "DEBUG: HarpService searching " . count($records) . " records for closest before {$datetime}\n";
        
        // Find the closest record before the specified datetime
        $closestRecord = $this->findClosestRecordBefore($records, $datetime);
        
        if (!$closestRecord) {
            echo "DEBUG: HarpService no record found before {$datetime} for HARP {$harpNumber}\n";
            return null;
        }
        
        echo "\n[SELECTED CLOSEST RECORD]:\n";
        foreach ($closestRecord as $key => $value) {
            echo "  {$key} => " . json_encode($value) . "\n";
        }
        $timeDiff = strtotime($datetime) - strtotime($this->formatJsocTime($closestRecord['time']));
        echo "  Time difference: " . round($timeDiff/3600, 2) . " hours before event\n";
        echo "\n";
        
        // Transform field names to match DaffProcessor expectations
        $result = [
            'harp_id' => $closestRecord['harp_id'],
            'noaa_id' => $closestRecord['noaa_id'],
            'location_time' => $this->formatJsocTime($closestRecord['time']),
            'latitude' => $closestRecord['lat'],
            'longitude' => $closestRecord['long'],
        ];
        
        echo "DEBUG: HarpService returning coordinates: lat=" . $result['latitude'] . ", lon=" . $result['longitude'] . ", time=" . $result['location_time'] . ", noaa=" . ($result['noaa_id'] ?: 'null') . "\n";
        
        return $result;
    }


    /**
     * Convert JSOC time format to standard datetime format
     * Handles formats like: 2013.06.19_12:00:00_TAI
     *
     * @param string $jsocTime Time from JSOC T_REC field
     * @return string Time in Y-m-d H:i:s format
     */
    private function formatJsocTime(string $jsocTime): string
    {
        // Remove _TAI suffix and replace dots/underscores
        $timeString = str_replace(['_TAI', '_UTC', '_'], ['', '', ' '], $jsocTime);
        $timeString = str_replace('.', '-', $timeString);
        
        // Convert to standard format
        $timestamp = strtotime($timeString);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : $jsocTime;
    }
}