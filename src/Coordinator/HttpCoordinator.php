<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Psr\SimpleCache\CacheInterface;
use Psr\Http\Client\ClientInterface;
use HelioviewerEventInterface\Coordinator\Coordinator;
use Exception;

/**
 * HTTP Coordinator Implementation
 * 
 * Implements coordinate transformation using HTTP-based Coordinator service
 * with caching support for performance optimization
 * 
 * @package    Helioviewer\EventsApi\Coordinator
 * @since      1.0.0
 */
class HttpCoordinator implements CoordinatorInterface
{
    private ClientInterface $client;
    private ?CacheInterface $cache;

    /**
     * Constructor
     *
     * @param ClientInterface $client HTTP client for coordinate transformation requests
     * @param CacheInterface|null $cache Optional cache interface for caching transformations
     */
    public function __construct(ClientInterface $client, ?CacheInterface $cache = null)
    {
        $this->client = $client;
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
        
        // Perform coordinate rotation using HTTP Coordinator
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

        // Prepare coordinates for batch request
        $coordinates = [];
        foreach ($coordinateArray as $coord) {
            $coordinates[] = [
                'lat' => $coord['lat'],
                'lon' => $coord['lon'],
                'coord_time' => date('Y-m-d\TH:i:s\Z', $coord['coordinate_time'])
            ];
        }
        // Make POST request to coordinator service using convenient postJson method
        try {
            $response = $this->client->postJson(
                HV_COORDINATOR_URL . '/hgs2hpc',
                [
                    'coordinates' => $coordinates,
                    'target' => $targetTime
                ]
            );

            if ($response->getStatusCode() !== 200) {
                throw new CoordinatorException("Coordinator service returned status: " . $response->getStatusCode());
            }

            $responseData = json_decode($response->getBody()->getContents(), true);

            if (!isset($responseData['coordinates']) || !is_array($responseData['coordinates'])) {
                throw new CoordinatorException("Invalid response format from coordinator service");
            }

            // Format the results
            $rotatedCoordinates = [];
            foreach ($responseData['coordinates'] as $index => $result) {
                $rotatedCoordinates[$index] = [
                    'hpc_x' => $result['x'],
                    'hpc_y' => $result['y'],
                    'original_hgs_lat' => $coordinateArray[$index]['lat'],
                    'original_hgs_lon' => $coordinateArray[$index]['lon'],
                    'target_time' => $targetTime,
                    'from_cache' => false
                ];
            }

            return $rotatedCoordinates;

        } catch (Exception $e) {
            throw new CoordinatorException("Failed to rotate coordinates: " . $e->getMessage());
        }
    }

}
