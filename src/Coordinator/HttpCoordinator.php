<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * HTTP Coordinator Implementation
 *
 * Implements coordinate transformation using HTTP-based Coordinator service
 *
 * @package    Helioviewer\EventsApi\Coordinator
 * @since      1.0.0
 */
class HttpCoordinator implements CoordinatorInterface
{
    private ClientInterface $client;
    private ?LoggerInterface $logger;
    private string $baseUrl;

    /**
     * Constructor
     *
     * @param ClientInterface $client HTTP client for coordinate transformation requests
     * @param LoggerInterface|null $logger Optional logger for debugging
     * @param string|null $baseUrl Base URL for coordinator service (defaults to $this->baseUrl)
     */
    public function __construct(ClientInterface $client, ?LoggerInterface $logger = null, ?string $baseUrl = null)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->baseUrl = $baseUrl ?? HV_COORDINATOR_URL;
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
        $parsedTimestamp = is_numeric($targetTimestamp) ? (int)$targetTimestamp : strtotime($targetTimestamp);
        $targetTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);
        $url = $this->baseUrl . '/hgs2hpc';
        $coordCount = count($coordinateArray);

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

        // Make POST request to coordinator service
        try {
            if ($this->logger) {
                $this->logger->info("HttpCoordinator | stonyhurst -> helioprojective | POST {$url} | {$coordCount} coordinates | Target: {$targetTime}");
            }

            $startTime = microtime(true);

            $response = $this->client->postJson($url, [
                'coordinates' => $coordinates,
                'target' => $targetTime
            ]);

            $elapsedMs = round((microtime(true) - $startTime) * 1000, 1);

            $body = $response->getBody()->getContents();

            if ($response->getStatusCode() !== 200) {
                if ($this->logger) {
                    $this->logger->error("HttpCoordinator | stonyhurst -> helioprojective | POST {$url} | Status: {$response->getStatusCode()} | {$elapsedMs}ms | Response: {$body}");
                }
                throw new CoordinatorException("Coordinator service returned status: " . $response->getStatusCode());
            }

            $responseData = json_decode($body, true);

            if (!isset($responseData['coordinates']) || !is_array($responseData['coordinates'])) {
                throw new CoordinatorException("Invalid response format from coordinator service");
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

            $resultCount = count($rotatedCoordinates);

            if ($this->logger) {
                $this->logger->info("HttpCoordinator | stonyhurst -> helioprojective | POST {$url} | {$coordCount} sent | {$resultCount} received | {$elapsedMs}ms");
            }

            return $rotatedCoordinates;

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("HttpCoordinator | stonyhurst -> helioprojective | POST {$url} | FAILED | {$coordCount} coordinates | " . $e->getMessage());
            }
            if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
                throw new CoordinatorConnectionException("Failed to connect for coordinate rotation: " . $e->getMessage(), 0, $e);
            }
            throw new CoordinatorException("Failed to rotate coordinates: " . $e->getMessage(), 0, $e);
        }
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

        $parsedTimestamp = is_numeric($targetTimestamp) ? (int)$targetTimestamp : strtotime($targetTimestamp);
        $targetTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);
        $url = $this->baseUrl . '/hpc';
        $coordCount = count($coordinateArray);

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

        // Make POST request to coordinator service
        try {
            if ($this->logger) {
                $this->logger->info("HttpCoordinator | helioprojective -> helioprojective | POST {$url} | {$coordCount} coordinates | Target: {$targetTime}");
            }

            $startTime = microtime(true);

            $response = $this->client->postJson($url, [
                'coordinates' => $coordinates,
                'target' => $targetTime
            ]);

            $elapsedMs = round((microtime(true) - $startTime) * 1000, 1);

            $body = $response->getBody()->getContents();

            if ($response->getStatusCode() !== 200) {
                if ($this->logger) {
                    $this->logger->error("HttpCoordinator | helioprojective -> helioprojective | POST {$url} | Status: {$response->getStatusCode()} | {$elapsedMs}ms | Response: {$body}");
                }
                throw new CoordinatorException("Coordinator service returned status: " . $response->getStatusCode());
            }

            $responseData = json_decode($body, true);

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

            $resultCount = count($transformedCoordinates);

            if ($this->logger) {
                $this->logger->info("HttpCoordinator | helioprojective -> helioprojective | POST {$url} | {$coordCount} sent | {$resultCount} received | {$elapsedMs}ms");
            }

            return $transformedCoordinates;

        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("HttpCoordinator | helioprojective -> helioprojective | POST {$url} | FAILED | {$coordCount} coordinates | " . $e->getMessage());
            }
            if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
                throw new CoordinatorConnectionException("Failed to connect for HPC transformation: " . $e->getMessage(), 0, $e);
            }
            throw new CoordinatorException("Failed to transform HPC coordinates: " . $e->getMessage(), 0, $e);
        }
    }
}
