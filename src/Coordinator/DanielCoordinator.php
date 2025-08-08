<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Psr\SimpleCache\CacheInterface;
use HelioviewerEventInterface\Coordinator\Coordinator;
use Helioviewer\EventsApi\Utils\TimestampParser;
use Exception;

/**
 * Daniel Coordinator Implementation
 * 
 * Implements coordinate transformation using Daniel's Coordinator library
 * with caching support for performance optimization
 * 
 * @package    Helioviewer\EventsApi\Coordinator
 * @since      1.0.0
 */
class DanielCoordinator implements CoordinatorInterface
{
    private ?CacheInterface $cache;
    private TimestampParser $timestampParser;
    
    /**
     * Constructor
     * 
     * @param CacheInterface|null $cache Optional cache interface for caching transformations
     */
    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache;
        $this->timestampParser = new TimestampParser();
    }
    
    /**
     * Rotate Stonyhurst (HGS) coordinates to Helioprojective Cartesian (HPC) at target time
     * 
     * @param float $latitude HGS latitude
     * @param float $longitude HGS longitude
     * @param string $coordinateTime Coordinate time in ISO 8601 format
     * @param string $targetTime Target time in ISO 8601 format
     * @return array Array with 'hpc_x' and 'hpc_y' keys containing Helioprojective Cartesian coordinates
     * @throws Exception If coordinate transformation fails
     */
    public function rotate(
        float $latitude,
        float $longitude,
        string $coordinateTime,
        string $targetTime
    ): array {
        // Create cache key if cache is available
        if ($this->cache !== null) {
            $cacheKey = sprintf(
                '%s:%s:%s:%s',
                $latitude,
                $longitude,
                $coordinateTime,
                $targetTime
            );
            
            // Check cache
            $cachedValue = $this->cache->get($cacheKey);
            if ($cachedValue !== null) {
                return $cachedValue;
            }
        }
        
        // Perform coordinate rotation using Daniel's Coordinator
        $rotatedCoords = Coordinator::Hgs2Hpc(
            $latitude,
            $longitude,
            $coordinateTime,
            $targetTime
        );
        
        // Format result with clear HPC labels
        $result = [
            'hpc_x' => $rotatedCoords['x'],
            'hpc_y' => $rotatedCoords['y']
        ];
        
        // Cache result if cache is available (24 hours TTL)
        if ($this->cache !== null && isset($cacheKey)) {
            $this->cache->set($cacheKey, $result, 86400);
        }
        
        return $result;
    }
    
    /**
     * Batch rotate multiple Stonyhurst coordinates to Helioprojective Cartesian
     * 
     * @param array $events Array of events with coordinate data
     * @param int|string $targetTimestamp Target time for coordinate rotation
     * @return array Dictionary with event ID as key and rotated HPC coordinates as value
     */
    public function rotateAll(array $events, $targetTimestamp): array
    {
        // Parse target timestamp
        $parsedTimestamp = $this->timestampParser->parse($targetTimestamp);
        $targetTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);
        
        $rotatedCoordinates = [];
        
        foreach ($events as $event) {
            // Handle both Event models and arrays
            $eventId = is_array($event) ? $event['id'] : $event->id;
            $coordinateTime = is_array($event) ? $event['coordinate_time'] : $event->coordinate_time;
            $hgsLatitude = is_array($event) ? $event['hv_hpc_x'] : $event->hv_hpc_x;
            $hgsLongitude = is_array($event) ? $event['hv_hpc_y'] : $event->hv_hpc_y;
            
            // Skip if no coordinates
            if ($hgsLatitude === null || $hgsLongitude === null || $coordinateTime === null) {
                $rotatedCoordinates[$eventId] = [
                    'hpc_x' => $hgsLatitude,
                    'hpc_y' => $hgsLongitude,
                    'rotation_error' => 'Missing coordinate data'
                ];
                continue;
            }
            
            // Create cache key
            $cacheKey = sprintf(
                '%s:%s:%d:%d',
                $hgsLatitude,
                $hgsLongitude,
                $coordinateTime,
                $parsedTimestamp
            );
            
            // Check cache if available
            if ($this->cache !== null) {
                $cachedValue = $this->cache->get($cacheKey);
                if ($cachedValue !== null) {
                    $rotatedCoordinates[$eventId] = array_merge($cachedValue, [
                        'original_hgs_lat' => $hgsLatitude,
                        'original_hgs_lon' => $hgsLongitude,
                        'target_time' => $targetTime,
                        'from_cache' => true
                    ]);
                    continue;
                }
            }
            
            // Format coordinate time
            $coordTime = date('Y-m-d\TH:i:s\Z', $coordinateTime);
            
            try {
                // Perform rotation
                $rotated = $this->rotate(
                    (float)$hgsLatitude,
                    (float)$hgsLongitude,
                    $coordTime,
                    $targetTime
                );
                
                $rotatedCoordinates[$eventId] = [
                    'hpc_x' => $rotated['hpc_x'],
                    'hpc_y' => $rotated['hpc_y'],
                    'original_hgs_lat' => $hgsLatitude,
                    'original_hgs_lon' => $hgsLongitude,
                    'target_time' => $targetTime,
                    'from_cache' => false
                ];
                
            } catch (Exception $e) {
                $rotatedCoordinates[$eventId] = [
                    'hpc_x' => $hgsLatitude,
                    'hpc_y' => $hgsLongitude,
                    'rotation_error' => $e->getMessage()
                ];
            }
        }
        
        return $rotatedCoordinates;
    }
}