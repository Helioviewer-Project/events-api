<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Sources;

use Psr\Http\Client\ClientInterface;
use GuzzleHttp\Client;

/**
 * Abstract base class for HTTP-based solar event data sources
 * 
 * Extends the base Source class with HTTP client functionality for sources
 * that fetch data from remote APIs. Provides PSR-18 HTTP client injection
 * for testability and flexibility. Creates a default Guzzle client if none provided.
 */
abstract class HttpSource extends Source
{
    /**
     * PSR-18 HTTP client for making API requests
     */
    protected ClientInterface $client;

    /**
     * Create a new HTTP source instance
     * 
     * @param string $path Unique identifier path for this source
     * @param ClientInterface|null $client PSR-18 HTTP client for API requests, creates Guzzle client if null
     */
    public function __construct(string $path, ?ClientInterface $client = null)
    {
        $this->client = $client ?? $this->createDefaultClient();
        parent::__construct($path);
    }

    /**
     * Build the HTTP request for fetching events within the given time range
     * 
     * @param int $start Start timestamp for event query
     * @param int $end End timestamp for event query
     * @return \Psr\Http\Message\RequestInterface The HTTP request object
     */
    abstract protected function request(int $start, int $end): \Psr\Http\Message\RequestInterface;

    /**
     * Process the HTTP response and extract raw data
     * 
     * @param \Psr\Http\Message\ResponseInterface $response HTTP response from the API
     * @return Event[] Array of Event model instances
     */
    abstract protected function processResponse(\Psr\Http\Message\ResponseInterface $response): array;

    /**
     * Fetch events from HTTP API for the given time range
     * 
     * @param int $start Start timestamp for event query
     * @param int $end End timestamp for event query
     * @return Event[] Array of Event model instances
     */
    public function fetch(int $start, int $end): array
    {
        $request = $this->request($start, $end);
        
        // Debug: Output the request URL
        echo "DEBUG: Requesting URL: " . $request->getUri() . "\n";
        
        try {
            $response = $this->client->sendRequest($request);
            
            // Check status code explicitly
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $reasonPhrase = $response->getReasonPhrase();
                echo "ERROR: HTTP {$statusCode} {$reasonPhrase} from {$request->getUri()}\n";
                error_log("HTTP {$statusCode} {$reasonPhrase} from {$request->getUri()}");
                return [];
            }
            
            // Process response using the concrete implementation
            return $this->processResponse($response);
            
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $statusCode = $e->getCode();
            echo "ERROR: HTTP {$statusCode} from {$request->getUri()}: " . $e->getMessage() . "\n";
            error_log("HTTP {$statusCode} from {$request->getUri()}: " . $e->getMessage());
            return [];
        } catch (\Exception $e) {
            error_log("Failed to fetch data from {$request->getUri()}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Create a default Guzzle HTTP client
     * 
     * @return ClientInterface Default configured Guzzle client
     */
    private function createDefaultClient(): ClientInterface
    {
        return new Client([
            'timeout' => 60.0,
            'http_errors' => true,
            'headers' => [
                'User-Agent' => 'Helioviewer Events API/1.0',
                'Accept' => 'application/json',
            ],
        ]);
    }
}
