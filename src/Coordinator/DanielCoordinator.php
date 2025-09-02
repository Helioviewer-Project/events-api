<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Psr\SimpleCache\CacheInterface;
use HelioviewerEventInterface\Coordinator\Coordinator;
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
    
    /**
     * Constructor
     * 
     * @param CacheInterface|null $cache Optional cache interface for caching transformations
     */
    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache;
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
     * Batch rotate coordinates using simplified array format
     * 
     * @param array $coordinateArray Array of coordinate data with 'lat', 'lon', 'coordinate_time' keys
     * @param int|string $targetTimestamp Target time for coordinate rotation
     * @return array Array of rotated coordinates in same order as input
     */
    public function rotateAll(array $coordinateArray, $targetTimestamp): array
    {
        // Convert target timestamp to ISO format
        $parsedTimestamp = is_numeric($targetTimestamp) ? (int)$targetTimestamp : strtotime($targetTimestamp);
        $targetTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);
        
        $rotatedCoordinates = [];
        
        foreach ($coordinateArray as $index => $coord) {
            $hgsLatitude = $coord['lat'];
            $hgsLongitude = $coord['lon'];
            $coordinateTime = $coord['coordinate_time'];
            
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
                    $rotatedCoordinates[$index] = array_merge($cachedValue, [
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
                
                $rotatedCoordinates[$index] = [
                    'hpc_x' => $rotated['hpc_x'],
                    'hpc_y' => $rotated['hpc_y'],
                    'original_hgs_lat' => $hgsLatitude,
                    'original_hgs_lon' => $hgsLongitude,
                    'target_time' => $targetTime,
                    'from_cache' => false
                ];
                
            } catch (Exception $e) {
                throw new CoordinatorException("Failed to rotate coordinates: " . $e->getMessage());
            }
        }
        
        return $rotatedCoordinates;
    }

}
