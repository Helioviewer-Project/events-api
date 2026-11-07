<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Helioviewer\EventsApi\Utils\CachedHttpClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

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
    private CachedHttpClient $client;
    private ?LoggerInterface $logger;
    private string $baseUrl;

    /**
     * Constructor
     *
     * @param CachedHttpClient $client HTTP client for coordinate transformation requests
     * @param LoggerInterface|null $logger Optional logger for debugging
     * @param string|null $baseUrl Base URL for coordinator service (defaults to $this->baseUrl)
     */
    public function __construct(CachedHttpClient $client, ?LoggerInterface $logger = null, ?string $baseUrl = null)
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
        return $this->batch('stonyhurst', $coordinateArray, $targetTimestamp);
    }

    /**
     * Batch rotate Heliographic Carrington coordinates to Helioprojective
     *
     * @param array $coordinateArray Array of coordinate data with 'lat', 'lon', 'coordinate_time' keys
     * @param int|string $targetTimestamp Target time for coordinate rotation
     * @return array Array of rotated coordinates in same order as input
     */
    public function carringtonToHelioprojectiveBatch(array $coordinateArray, $targetTimestamp): array
    {
        return $this->batch('carrington', $coordinateArray, $targetTimestamp);
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
        return $this->batch('helioprojective', $coordinateArray, $targetTimestamp);
    }

    /**
     * One batch call for any coordinate system, waiting for the answer.
     *
     * @param string $system carrington, stonyhurst, or helioprojective
     * @param array $coordinateArray Coordinates keyed by event id
     * @param int|string $targetTimestamp Target observation time
     * @return array Rotated coordinates keyed like the input
     * @throws CoordinatorException
     */
    public function batch(string $system, array $coordinateArray, $targetTimestamp): array
    {
        if (empty($coordinateArray)) {
            return [];
        }
        $call = $this->prepare($system, $coordinateArray, $targetTimestamp);

        try {
            return $this->parse($call, $this->client->postJson($call['url'], $call['payload']));
        } catch (\Exception $e) {
            throw $this->failure($call, $e);
        }
    }

    /**
     * batch() as a promise, so a caller can keep several calls in flight.
     *
     * @param string $system carrington, stonyhurst, or helioprojective
     * @param array $coordinateArray Coordinates keyed by event id
     * @param int|string $targetTimestamp Target observation time
     * @return PromiseInterface Resolves like batch(); rejects with CoordinatorException
     */
    public function batchAsync(string $system, array $coordinateArray, $targetTimestamp): PromiseInterface
    {
        if (empty($coordinateArray)) {
            return Create::promiseFor([]);
        }
        $call = $this->prepare($system, $coordinateArray, $targetTimestamp);

        return $this->client->postJsonAsync($call['url'], $call['payload'])
            ->then(fn(ResponseInterface $response) => $this->parse($call, $response))
            ->otherwise(function (\Throwable $e) use ($call) {
                if (!$e instanceof \Exception) {
                    throw $e; // a PHP error is a bug, not a coordinator failure
                }
                throw $this->failure($call, $e);
            });
    }

    /**
     * Build the request for one batch call and log it.
     *
     * @param string $system carrington, stonyhurst, or helioprojective
     * @param array $coordinateArray Coordinates keyed by event id
     * @param int|string $targetTimestamp Target observation time
     * @return array url, payload, and what parse() and failure() need
     */
    private function prepare(string $system, array $coordinateArray, $targetTimestamp): array
    {
        $parsedTimestamp = is_numeric($targetTimestamp) ? (int)$targetTimestamp : strtotime($targetTimestamp);
        $targetTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);
        [$route, $fields] = match ($system) {
            'stonyhurst'      => ['hgs2hpc', ['lat', 'lon']],
            'carrington'      => ['hgc2hpc', ['lat', 'lon']],
            'helioprojective' => ['hpc', ['x', 'y']],
        };

        $coordinates = [];
        foreach ($coordinateArray as $coord) {
            $coordinates[] = [
                $fields[0] => $coord[$fields[0]],
                $fields[1] => $coord[$fields[1]],
                'coord_time' => date('Y-m-d\TH:i:s\Z', $coord['coordinate_time'])
            ];
        }
        $payload = ['coordinates' => $coordinates, 'target' => $targetTime];
        if ($system === 'carrington') {
            $payload['observer'] = 'earth';
        }

        $call = [
            'system'  => $system,
            'label'   => "{$system} -> helioprojective",
            'url'     => $this->baseUrl . '/' . $route,
            'payload' => $payload,
            'count'   => count($coordinateArray),
            // Results come back by position, so remember which event each one is
            'keys'    => array_keys($coordinateArray),
            'start'   => microtime(true),
        ];

        if ($this->logger) {
            $this->logger->info("HttpCoordinator | {$call['label']} | POST {$call['url']} | {$call['count']} coordinates | Target: {$targetTime}");
        }

        return $call;
    }

    /**
     * Check a coordinator reply and map its results back to event ids.
     *
     * @param array $call From prepare()
     * @param ResponseInterface $response Coordinator reply
     * @return array Rotated coordinates keyed like the input
     * @throws CoordinatorException
     */
    private function parse(array $call, ResponseInterface $response): array
    {
        $elapsedMs = round((microtime(true) - $call['start']) * 1000, 1);
        $body = $response->getBody()->getContents();

        if ($response->getStatusCode() !== 200) {
            if ($this->logger) {
                $this->logger->error("HttpCoordinator | {$call['label']} | POST {$call['url']} | Status: {$response->getStatusCode()} | {$elapsedMs}ms | Response: {$body}");
            }
            throw new CoordinatorException("Coordinator service returned status: " . $response->getStatusCode());
        }

        $responseData = json_decode($body, true);

        if (!isset($responseData['coordinates']) || !is_array($responseData['coordinates'])) {
            throw new CoordinatorException("Invalid response format from coordinator service");
        }

        // Results are matched to events by POSITION, so a short reply would
        // silently leave the tail of the batch on its old coordinates and a
        // long one would index past the keys. Fail loudly instead.
        $returned = count($responseData['coordinates']);
        if ($returned !== $call['count']) {
            throw new CoordinatorException(
                "Coordinator returned {$returned} coordinates for {$call['count']} sent"
            );
        }

        $rotatedCoordinates = [];
        foreach ($responseData['coordinates'] as $index => $result) {
            $rotatedCoordinates[$call['keys'][$index]] = $this->formatResult($result);
        }

        if ($this->logger) {
            $this->logger->info("HttpCoordinator | {$call['label']} | POST {$call['url']} | {$call['count']} sent | " . count($rotatedCoordinates) . " received | {$elapsedMs}ms");
        }

        return $rotatedCoordinates;
    }

    /**
     * Log a failed call and turn it into the exception callers expect.
     *
     * @param array $call From prepare()
     * @param \Exception $e What went wrong
     * @return CoordinatorException
     */
    private function failure(array $call, \Exception $e): CoordinatorException
    {
        if ($this->logger) {
            $this->logger->error("HttpCoordinator | {$call['label']} | POST {$call['url']} | FAILED | {$call['count']} coordinates | " . $e->getMessage());
        }
        $hpc = $call['system'] === 'helioprojective';
        if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
            return new CoordinatorConnectionException(
                ($hpc ? 'Failed to connect for HPC transformation: ' : 'Failed to connect for coordinate rotation: ') . $e->getMessage(), 0, $e
            );
        }
        return new CoordinatorException(
            ($hpc ? 'Failed to transform HPC coordinates: ' : 'Failed to rotate coordinates: ') . $e->getMessage(), 0, $e
        );
    }

    /**
     * One coordinator result as an hpc_x/hpc_y pair, carrying `visible` only
     * when the service reported it — coordinator builds without the flag leave
     * the key out, and every consumer reads a missing key as visible.
     *
     * @param array $result One entry of the coordinator's coordinates array
     * @return array
     */
    private function formatResult(array $result): array
    {
        $formatted = [
            'hpc_x' => $result['x'],
            'hpc_y' => $result['y'],
        ];

        if (array_key_exists('visible', $result)) {
            $formatted['visible'] = (bool) $result['visible'];
        }

        return $formatted;
    }
}
