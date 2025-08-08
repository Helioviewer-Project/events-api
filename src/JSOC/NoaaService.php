<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\JSOC;

use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * NOAA Service for querying NOAA Active Region data from JSOC.
 *
 * @package    Helioviewer\EventsApi\JSOC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov> 
 * @since      1.0.0
 */
class NoaaService
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
        
        // Debug first few records to see time fields
        echo "DEBUG: Checking first 3 records for time fields:\n";
        for ($i = 0; $i < min(3, count($records)); $i++) {
            $timeField = isset($records[$i]['ObservationTime']) ? 'ObservationTime' : (isset($records[$i]['time']) ? 'time' : 'UNKNOWN');
            $timeValue = $records[$i][$timeField] ?? 'N/A';
            echo "  Record[$i] {$timeField}: {$timeValue}\n";
        }
        
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
        echo "DEBUG: NoaaService looking for NOAA AR {$noaaNumber} before {$datetime}\n";
        
        // Cache key for JSOC records
        $cacheKey = "1bust_noaa_service_{$noaaNumber}";
        
        $records = null;
        
        // Try to get JSOC records from cache first
        if ($this->cache) {
            try {
                $records = $this->cache->get($cacheKey);
                if ($records !== null) {
                    echo "DEBUG: NoaaService cache HIT for NOAA AR {$noaaNumber} - found " . count($records) . " records\n";
                    if (!empty($records)) {
                        echo "\n[CACHE SAMPLE ROW]:\n";
                        $sample = $records[0];
                        foreach ($sample as $key => $value) {
                            echo "  {$key} => " . json_encode($value) . "\n";
                        }
                        echo "\n";
                    }
                } else {
                    echo "DEBUG: NoaaService cache MISS for NOAA AR {$noaaNumber}\n";
                }
            } catch (\Exception $e) {
                echo "DEBUG: NoaaService cache read error for NOAA AR {$noaaNumber}: " . $e->getMessage() . "\n";
                error_log("Cache read error for NOAA AR {$noaaNumber}: " . $e->getMessage());
            }
        }
        
        // If not in cache, fetch from JSOC
        if ($records === null) {
            echo "DEBUG: NoaaService fetching NOAA AR {$noaaNumber} from JSOC...\n";
            try {
                $records = $this->jsoc->fetchNoaaActiveRegions($noaaNumber);
                
                if ($records) {
                    echo "DEBUG: NoaaService fetched " . count($records) . " records from JSOC for NOAA AR {$noaaNumber}\n";
                    if (!empty($records)) {
                        echo "\n[JSOC SAMPLE ROW]:\n";
                        $sample = $records[0];
                        foreach ($sample as $key => $value) {
                            echo "  {$key} => " . json_encode($value) . "\n";
                        }
                        if (isset($sample['LongitudeCM'])) {
                            echo "  NOTE: Using LongitudeCM for longitude coordinate\n";
                        }
                        echo "\n";
                    }
                } else {
                    echo "DEBUG: NoaaService no records found in JSOC for NOAA AR {$noaaNumber}\n";
                }
                
                // Cache the JSOC records
                if ($this->cache && $records) {
                    try {
                        $this->cache->set($cacheKey, $records, $this->cacheTtl);
                        echo "DEBUG: NoaaService cached " . count($records) . " records for NOAA AR {$noaaNumber}\n";
                    } catch (\Exception $e) {
                        echo "DEBUG: NoaaService cache write error for NOAA AR {$noaaNumber}: " . $e->getMessage() . "\n";
                        error_log("Cache write error for NOAA AR {$noaaNumber}: " . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                echo "DEBUG: NoaaService failed to fetch NOAA AR {$noaaNumber} from JSOC: " . $e->getMessage() . "\n";
                error_log("Failed to fetch NOAA AR {$noaaNumber} from JSOC: " . $e->getMessage());
                return null;
            }
        }
        
        if (!$records || empty($records)) {
            echo "DEBUG: NoaaService no records available for NOAA AR {$noaaNumber}\n";
            return null;
        }
        
        echo "DEBUG: NoaaService searching " . count($records) . " records for closest before {$datetime}\n";
        
        // Find the closest record before the specified datetime
        $closestRecord = $this->findClosestRecordBefore($records, $datetime);
        
        if (!$closestRecord) {
            echo "DEBUG: NoaaService no record found before {$datetime} for NOAA AR {$noaaNumber}\n";
            return null;
        }
        
        echo "DEBUG: NoaaService found closest record: " . $closestRecord['ObservationTime'] . " for NOAA AR {$noaaNumber}\n";
        
        // Return fields using LongitudeCM for proper heliographic longitude
        $result = [
            'noaa_id' => (string) $closestRecord['RegionNumber'],
            'location_time' => $this->formatJsocTime($closestRecord['ObservationTime']),
            'latitude' => (float) $closestRecord['LatitudeHG'],
            'longitude' => (float) ($closestRecord['LongitudeCM'] ?? 0.0),
        ];
        
        
        echo "DEBUG: NoaaService returning coordinates: lat=" . $result['latitude'] . ", lon=" . $result['longitude'] . ", time=" . $result['location_time'] . "\n";
        
        return $result;
    }

    /**
     * Get last known NOAA region coordinate data by NOAA Active Region number before specified datetime (from HARP logs).
     *
     * @param int $noaaNumber The NOAA active region number
     * @param string $datetime The datetime to find coordinates before (Y-m-d H:i:s format)
     * @return array|null NOAA record array or null if not found
     */
    public function getLastCoordinateForNoaaFromHarpLogs(int $noaaNumber, string $datetime): ?array
    {
        echo "DEBUG: NoaaService looking for NOAA AR {$noaaNumber} before {$datetime} (from HARP logs)\n";
        
        // Extract date for JSOC query - go queryDays earlier
        $date = date('Y-m-d', strtotime($datetime . " -{$this->queryDays} days"));
        
        // Cache key for JSOC records includes date and days
        $cacheKey = "noaa_hmi_{$noaaNumber}_{$date}_{$this->queryDays}d";
        
        $records = null;
        
        // Try to get JSOC records from cache first
        if ($this->cache) {
            try {
                $records = $this->cache->get($cacheKey);
                if ($records !== null) {
                    echo "DEBUG: NoaaService cache HIT for NOAA AR {$noaaNumber} (HARP logs) - found " . count($records) . " records\n";
                    if (!empty($records)) {
                        echo "\n[CACHE SAMPLE ROW]:\n";
                        $sample = $records[0];
                        foreach ($sample as $key => $value) {
                            echo "  {$key} => " . json_encode($value) . "\n";
                        }
                        echo "\n";
                    }
                } else {
                    echo "DEBUG: NoaaService cache MISS for NOAA AR {$noaaNumber} (HARP logs)\n";
                }
            } catch (\Exception $e) {
                echo "DEBUG: NoaaService cache read error for NOAA AR {$noaaNumber} (HARP logs): " . $e->getMessage() . "\n";
                error_log("Cache read error for NOAA AR {$noaaNumber}: " . $e->getMessage());
            }
        }
        
        // If not in cache, fetch from JSOC
        if ($records === null) {
            echo "DEBUG: NoaaService fetching NOAA AR {$noaaNumber} from HARP logs ({$date}, {$this->queryDays} days)...\n";
            try {
                $records = $this->jsoc->fetchByNoaaNumber($noaaNumber, $date, $this->queryDays);
                
                if ($records) {
                    echo "DEBUG: NoaaService fetched " . count($records) . " records from HARP logs for NOAA AR {$noaaNumber}\n";
                    if (!empty($records)) {
                        echo "\n[JSOC SAMPLE ROW]:\n";
                        $sample = $records[0];
                        foreach ($sample as $key => $value) {
                            echo "  {$key} => " . json_encode($value) . "\n";
                        }
                        echo "\n";
                    }
                } else {
                    echo "DEBUG: NoaaService no records found in HARP logs for NOAA AR {$noaaNumber}\n";
                }
                
                // Cache the JSOC records
                if ($this->cache && $records) {
                    try {
                        $this->cache->set($cacheKey, $records, $this->cacheTtl);
                        echo "DEBUG: NoaaService cached " . count($records) . " records for NOAA AR {$noaaNumber} (HARP logs)\n";
                    } catch (\Exception $e) {
                        echo "DEBUG: NoaaService cache write error for NOAA AR {$noaaNumber} (HARP logs): " . $e->getMessage() . "\n";
                        error_log("Cache write error for NOAA AR {$noaaNumber}: " . $e->getMessage());
                    }
                }
            } catch (\Exception $e) {
                echo "DEBUG: NoaaService failed to fetch NOAA AR {$noaaNumber} from HARP logs: " . $e->getMessage() . "\n";
                error_log("Failed to fetch NOAA AR {$noaaNumber} from JSOC: " . $e->getMessage());
                return null;
            }
        }
        
        if (!$records || empty($records)) {
            echo "DEBUG: NoaaService no records available for NOAA AR {$noaaNumber} (HARP logs)\n";
            return null;
        }
        
        echo "DEBUG: NoaaService searching " . count($records) . " records for closest before {$datetime} (NOAA AR {$noaaNumber}, HARP logs)\n";
        
        // Find the closest record before the specified datetime
        $closestRecord = $this->findClosestRecordBefore($records, $datetime);
        
        if (!$closestRecord) {
            echo "DEBUG: NoaaService no record found before {$datetime} for NOAA AR {$noaaNumber} (HARP logs)\n";
            return null;
        }
        
        echo "\n[SELECTED CLOSEST RECORD]:\n";
        foreach ($closestRecord as $key => $value) {
            echo "  {$key} => " . json_encode($value) . "\n";
        }
        $timeDiff = strtotime($datetime) - strtotime($this->formatJsocTimeHmi($closestRecord['time']));
        echo "  Time difference: " . round($timeDiff/3600, 2) . " hours before event\n";
        echo "\n";
        
        // Transform field names to match DaffProcessor expectations
        $result = [
            'harp_id' => $closestRecord['harp_id'],
            'noaa_id' => $closestRecord['noaa_id'],
            'location_time' => $this->formatJsocTimeHmi($closestRecord['time']),
            'latitude' => $closestRecord['lat'],
            'longitude' => $closestRecord['long'],
        ];
        
        echo "DEBUG: NoaaService returning NOAA coordinates (HARP logs): lat=" . $result['latitude'] . ", lon=" . $result['longitude'] . ", time=" . $result['location_time'] . ", harp=" . ($result['harp_id'] ?: 'null') . "\n";
        
        return $result;
    }

    /**
     * Convert JSOC HMI time format to standard datetime format
     * Handles formats like: 2013.06.19_12:00:00_TAI
     *
     * @param string $jsocTime Time from JSOC T_REC field
     * @return string Time in Y-m-d H:i:s format
     */
    private function formatJsocTimeHmi(string $jsocTime): string
    {
        // Remove _TAI suffix and replace dots/underscores
        $timeString = str_replace(['_TAI', '_UTC', '_'], ['', '', ' '], $jsocTime);
        $timeString = str_replace('.', '-', $timeString);
        
        // Convert to standard format
        $timestamp = strtotime($timeString);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : $jsocTime;
    }

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