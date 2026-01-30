<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Psr\SimpleCache\CacheInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use HelioviewerEventInterface\Coordinator\Coordinator;
use Exception;
use BadMethodCallException;

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
    private ?LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param ClientInterface $client HTTP client for coordinate transformation requests
     * @param CacheInterface|null $cache Optional cache interface for caching transformations
     * @param LoggerInterface|null $logger Optional logger for debugging
     */
    public function __construct(ClientInterface $client, ?CacheInterface $cache = null, ?LoggerInterface $logger = null)
    {
        $this->client = $client;
        $this->cache = $cache;
        $this->logger = $logger;

        if ($this->logger) {
            $clientClass = get_class($client);
            $cacheClass = $cache ? get_class($cache) : 'none';
            $this->logger->debug("HttpCoordinator | Initialized | Client: {$clientClass} | Cache: {$cacheClass}");
        }
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
    public function stonyhurstToHelioprojective(
        float $latitude,
        float $longitude,
        string $coordinateTime,
        string $targetTime
    ): array {
        if ($this->logger) {
            $this->logger->debug("HttpCoordinator | Single coordinate transformation | HGS: ({$latitude}, {$longitude}) | CoordTime: {$coordinateTime} | Target: {$targetTime}");
        }

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
                if ($this->logger) {
                    $this->logger->debug("HttpCoordinator | Cache HIT for single transformation | HPC: ({$cachedValue['hpc_x']}, {$cachedValue['hpc_y']})");
                }
                return $cachedValue;
            }

            if ($this->logger) {
                $this->logger->debug("HttpCoordinator | Cache MISS for single transformation | Calling Coordinator::Hgs2Hpc");
            }
        }

        try {
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

            if ($this->logger) {
                $this->logger->debug("HttpCoordinator | Single transformation completed | HPC: ({$result['hpc_x']}, {$result['hpc_y']})");
            }

            // Cache result if cache is available (24 hours TTL)
            if ($this->cache !== null && isset($cacheKey)) {
                $this->cache->set($cacheKey, $result, 86400);
                if ($this->logger) {
                    $this->logger->debug("HttpCoordinator | Cached single transformation result with TTL 86400s");
                }
            }

            return $result;

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("HttpCoordinator | Single transformation failed | Error: " . $e->getMessage());
            }
            throw $e;
        }
    }
    
    /**
     * Generate cache key for batch rotation request
     *
     * @param array $coordinates Formatted coordinates array
     * @param string $targetTime Target time in ISO format
     * @return string Cache key
     */
    private function generateBatchCacheKey(array $coordinates, string $targetTime): string
    {
        $cacheKeyData = json_encode([
            'coordinates' => $coordinates,
            'target' => $targetTime
        ]);
        $cacheKey = 'coordinator:rotateAll:' . md5($cacheKeyData);

        if ($this->logger) {
            $coordCount = count($coordinates);
            $this->logger->debug("HttpCoordinator | Generated batch cache key | Coordinates: {$coordCount} | Target: {$targetTime} | Key: {$cacheKey}");
        }

        return $cacheKey;
    }

    /**
     * Batch rotate coordinates using simplified array format
     *
     * @param array $coordinateArray Array of coordinate data with 'lat', 'lon', 'coordinate_time' keys
     * @param int|string $targetTimestamp Target time for coordinate rotation
     * @return array Array of rotated coordinates in same order as input
     */
    public function stonyhurstToHelioprojectiveBatch(array $coordinateArray, $targetTimestamp): array
    {
        // Convert target timestamp to ISO format
        $parsedTimestamp = is_numeric($targetTimestamp) ? (int)$targetTimestamp : strtotime($targetTimestamp);
        $targetTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);

        // Prepare coordinates for batch request, track original keys
        $coordinates = [];
        $originalKeys = array_keys($coordinateArray);
        foreach ($coordinateArray as $coord) {
            $coordinates[] = [
                'lat' => $coord['lat'],
                'lon' => $coord['lon'],
                'coord_time' => date('Y-m-d\TH:i:s\Z', $coord['coordinate_time'])
            ];
        }

        if ($this->logger) {
            $coordCount = count($coordinates);
            $this->logger->debug("HttpCoordinator | Prepared {$coordCount} coordinates for batch transformation:");

            // Show first few coordinates and summary for large batches
            $maxShow = 5;
            $showCount = min($coordCount, $maxShow);

            for ($i = 0; $i < $showCount; $i++) {
                $coord = $coordinates[$i];
                $this->logger->debug("  [{$i}] HGS: ({$coord['lat']}, {$coord['lon']}) at {$coord['coord_time']}");
            }

            if ($coordCount > $maxShow) {
                $remaining = $coordCount - $maxShow;
                $this->logger->debug("  ... and {$remaining} more coordinates");
            }
        }

        // Generate unique cache key based on all inputs
        $cacheKey = $this->generateBatchCacheKey($coordinates, $targetTime);

        // Check cache first if available
        if ($this->cache !== null) {
            $cachedResult = $this->cache->get($cacheKey);
            if ($cachedResult !== null) {
                if ($this->logger) {
                    $coordCount = count($coordinateArray);
                    $this->logger->debug("HttpCoordinator | Cache HIT for batch transformation of {$coordCount} coordinates | Target: {$targetTime}");
                    $this->logger->debug("HttpCoordinator | Cached coordinates result:");

                    // Show first few results and summary for large batches
                    $maxShow = 5;
                    $keys = array_keys($cachedResult);
                    $showCount = min(count($keys), $maxShow);

                    for ($i = 0; $i < $showCount; $i++) {
                        $key = $keys[$i];
                        $result = $cachedResult[$key];
                        $this->logger->debug("  [{$key}] HPC: ({$result['hpc_x']}, {$result['hpc_y']})");
                    }

                    if (count($keys) > $maxShow) {
                        $remaining = count($keys) - $maxShow;
                        $this->logger->debug("  ... and {$remaining} more coordinate transformations");
                    }
                }
                return $cachedResult;
            }
        }

        // Make POST request to coordinator service using convenient postJson method
        try {
            if ($this->logger) {
                $coordCount = count($coordinateArray);
                $url = HV_COORDINATOR_URL . '/hgs2hpc';
                $this->logger->debug("HttpCoordinator | Cache MISS for batch transformation of {$coordCount} coordinates | Target: {$targetTime}");
                $this->logger->debug("HttpCoordinator | Making POST request to {$url}");
            }

            $response = $this->client->postJson(
                HV_COORDINATOR_URL . '/hgs2hpc',
                [
                    'coordinates' => $coordinates,
                    'target' => $targetTime
                ]
            );

            if ($this->logger) {
                $statusCode = $response->getStatusCode();
                $this->logger->debug("HttpCoordinator | Coordinator service response | Status: {$statusCode}");
            }

            if ($response->getStatusCode() !== 200) {
                if ($this->logger) {
                    $this->logger->error("HttpCoordinator | Coordinator service error | Status: " . $response->getStatusCode());
                }
                throw new CoordinatorException("Coordinator service returned status: " . $response->getStatusCode());
            }

            $responseData = json_decode($response->getBody()->getContents(), true);

            if (!isset($responseData['coordinates']) || !is_array($responseData['coordinates'])) {
                if ($this->logger) {
                    $this->logger->error("HttpCoordinator | Invalid response format from coordinator service | Missing 'coordinates' array");
                }
                throw new CoordinatorException("Invalid response format from coordinator service");
            }

            if ($this->logger) {
                $resultCount = count($responseData['coordinates']);
                $this->logger->debug("HttpCoordinator | Received {$resultCount} transformed coordinates from service");
            }

            // Format the results, restoring original keys
            $rotatedCoordinates = [];
            foreach ($responseData['coordinates'] as $index => $result) {
                $originalKey = $originalKeys[$index];
                $rotatedCoordinates[$originalKey] = [
                    'hpc_x' => $result['x'],
                    'hpc_y' => $result['y'],
                ];
            }

            if ($this->logger) {
                $this->logger->debug("HttpCoordinator | Transformed coordinates result:");

                // Show first few results and summary for large batches
                $maxShow = 5;
                $keys = array_keys($rotatedCoordinates);
                $showCount = min(count($keys), $maxShow);

                for ($i = 0; $i < $showCount; $i++) {
                    $key = $keys[$i];
                    $result = $rotatedCoordinates[$key];
                    $this->logger->debug("  [{$key}] HPC: ({$result['hpc_x']}, {$result['hpc_y']})");
                }

                if (count($keys) > $maxShow) {
                    $remaining = count($keys) - $maxShow;
                    $this->logger->debug("  ... and {$remaining} more coordinate transformations");
                }
            }

            // Cache the result for 1 day (86400 seconds)
            if ($this->cache !== null) {
                $this->cache->set($cacheKey, $rotatedCoordinates, 86400);
                if ($this->logger) {
                    $this->logger->debug("HttpCoordinator | Cached batch transformation result with TTL 86400s");
                }
            }

            if ($this->logger) {
                $this->logger->debug("HttpCoordinator | Batch transformation completed successfully");
            }

            return $rotatedCoordinates;

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("HttpCoordinator | Batch transformation failed | Error: " . $e->getMessage());
            }
            throw new CoordinatorException("Failed to rotate coordinates: " . $e->getMessage());
        }
    }

    /**
     * Transform HPC coordinates to Stonyhurst (not implemented for HTTP)
     *
     * @param float $hpcX Helioprojective X in arcseconds
     * @param float $hpcY Helioprojective Y in arcseconds
     * @param string $obsTime Observation time in ISO 8601 format
     * @return array
     * @throws BadMethodCallException Always thrown - not implemented
     */
    public function helioprojectiveFromEarthToStonyhurst(float $hpcX, float $hpcY, string $obsTime): array
    {
        throw new BadMethodCallException('HPC to Stonyhurst coordinates transformation is not implemented in the Coordinator API (yet!)');
    }

    /**
     * Batch transform HPC coordinates to Stonyhurst (not implemented for HTTP)
     *
     * @param array $coordinateArray Array of coordinates
     * @return array
     * @throws BadMethodCallException Always thrown - not implemented
     */
    public function helioprojectiveFromEarthToStonyhurstBatch(array $coordinateArray): array
    {
        throw new BadMethodCallException('Batch HPC to Stonyhurst coordinates transformation is not implemented in the Coordinator API (yet!)');
    }

    /**
     * Batch transform HPC coordinates to HPC at a different observation time
     *
     * @param array $coordinateArray Array of coordinates with 'x', 'y', 'coordinate_time' keys
     * @param int|string $targetTimestamp Target observation time
     * @return array Array of transformed coordinates with same keys as input
     * @throws CoordinatorException If transformation fails
     */
    public function helioprojectiveToHelioprojectiveBatch(array $coordinateArray, $targetTimestamp): array
    {

        if (empty($coordinateArray)) {
            return [];
        }

        // Convert target timestamp to ISO format
        $parsedTimestamp = is_numeric($targetTimestamp) ? (int)$targetTimestamp : strtotime($targetTimestamp);
        $targetTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);

        // Prepare coordinates for batch request, track original keys
        $coordinates = [];
        $originalKeys = array_keys($coordinateArray);
        foreach ($coordinateArray as $coord) {
            $coordinates[] = [
                'x' => $coord['x'],
                'y' => $coord['y'],
                'coord_time' => date('Y-m-d\TH:i:s\Z', $coord['coordinate_time'])
            ];
        }

        if ($this->logger) {
            $coordCount = count($coordinates);
            $this->logger->debug("HttpCoordinator | Prepared {$coordCount} HPC coordinates for batch transformation");
        }

        // Generate unique cache key
        $cacheKey = 'hpc_batch:' . md5(serialize($coordinates) . $targetTime);

        // Check cache first
        if ($this->cache !== null) {
            $cachedResult = $this->cache->get($cacheKey);
            if ($cachedResult !== null) {
                if ($this->logger) {
                    $this->logger->debug("HttpCoordinator | Cache HIT for HPC batch transformation");
                }
                return $cachedResult;
            }
        }

        // Make POST request to coordinator service
        try {
            if ($this->logger) {
                $url = HV_COORDINATOR_URL . '/hpc';
                $this->logger->debug("HttpCoordinator | Making POST request to {$url}");
            }

            $response = $this->client->postJson(
                HV_COORDINATOR_URL . '/hpc',
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

            // Format the results, restoring original keys
            $transformedCoordinates = [];
            foreach ($responseData['coordinates'] as $index => $result) {
                $originalKey = $originalKeys[$index];
                $transformedCoordinates[$originalKey] = [
                    'hpc_x' => $result['x'],
                    'hpc_y' => $result['y'],
                ];
            }

            // Cache the result
            if ($this->cache !== null) {
                $this->cache->set($cacheKey, $transformedCoordinates, 86400);
            }

            if ($this->logger) {
                $this->logger->debug("HttpCoordinator | HPC batch transformation completed: " . count($transformedCoordinates) . " coordinates");
            }

            return $transformedCoordinates;

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("HttpCoordinator | HPC batch transformation failed: " . $e->getMessage());
            }
            throw new CoordinatorException("Failed to transform HPC coordinates: " . $e->getMessage());
        }
    }
}
