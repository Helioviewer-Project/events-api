<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Utils;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;

/**
 * HTTP Client with caching capabilities for source requests
 *
 * @package    Helioviewer\EventsApi\Utils
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class CachedHttpClient implements ClientInterface
{
    private ClientInterface $client;
    private ?CacheInterface $cache;
    private int $cacheTtl;
    private string $cachePrefix;

    public function __construct(
        ?ClientInterface $client = null, 
        ?CacheInterface $cache = null, 
        int $cacheTtl = 3600,
        string $cachePrefix = 'http_client:'
    ) {
        $this->client = $client ?? new Client([
            'timeout' => 60.0,
            'http_errors' => true,
            'headers' => [
                'User-Agent' => 'Helioviewer Events API/1.0',
                'Accept' => 'application/json',
            ],
        ]);
        $this->cache = $cache;
        $this->cacheTtl = $cacheTtl;
        $this->cachePrefix = $cachePrefix;
    }

    /**
     * Sends a PSR-7 request and returns a PSR-7 response.
     *
     * @param RequestInterface $request The request to send
     * @return ResponseInterface The response
     * @throws \Psr\Http\Client\ClientExceptionInterface If an error happens while processing the request
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Create cache key from request
        $cacheKey = $this->createCacheKey($request);
        
        // Try to get from cache first (only for GET requests)
        if ($this->cache && $request->getMethod() === 'GET') {
            try {
                $cachedResponse = $this->cache->get($cacheKey);
                if ($cachedResponse !== null) {
                    echo "Cache HIT for: " . $request->getUri() . "\n";
                    return $this->deserializeResponse($cachedResponse);
                }
            } catch (\Exception $e) {
                error_log("Cache read error for HTTP request: " . $e->getMessage());
            }
        }
        
        // Make the actual HTTP request
        echo "Cache MISS for: " . $request->getUri() . "\n";
        $response = $this->client->sendRequest($request);
        
        // Cache successful GET responses
        if ($this->cache && $request->getMethod() === 'GET' && $response->getStatusCode() < 400) {
            try {
                $serializedResponse = $this->serializeResponse($response);
                $this->cache->set($cacheKey, $serializedResponse, $this->cacheTtl);
            } catch (\Exception $e) {
                error_log("Cache write error for HTTP request: " . $e->getMessage());
            }
        }
        
        // Rewind body stream before returning fresh response
        $response->getBody()->rewind();
        return $response;
    }

    /**
     * Guzzle-compatible request method for compatibility with existing code
     *
     * @param string $method HTTP method
     * @param string $uri URI to request
     * @param array $options Request options (headers, body, etc.)
     * @return ResponseInterface The HTTP response
     * @throws \Psr\Http\Client\ClientExceptionInterface If an error happens while processing the request
     */
    public function request(string $method, string $uri, array $options = []): ResponseInterface
    {
        // Create PSR-7 request from Guzzle-style parameters
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? null;
        
        $request = new Request($method, $uri, $headers, $body);
        
        // Use our cached sendRequest implementation
        return $this->sendRequest($request);
    }

    /**
     * Create a cache key from the request
     *
     * @param RequestInterface $request The HTTP request
     * @return string The cache key
     */
    private function createCacheKey(RequestInterface $request): string
    {
        $uri = (string) $request->getUri();
        $method = $request->getMethod();
        $body = (string) $request->getBody();
        
        // Include request body for POST requests
        $keyData = $method . ':' . $uri;
        if ($body) {
            $keyData .= ':' . $body;
        }
        
        return $this->cachePrefix . md5($keyData);
    }

    /**
     * Serialize response for caching
     *
     * @param ResponseInterface $response The HTTP response
     * @return array Serialized response data
     */
    private function serializeResponse(ResponseInterface $response): array
    {
        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
            'body' => (string) $response->getBody(),
            'reason' => $response->getReasonPhrase(),
            'protocol' => $response->getProtocolVersion(),
        ];
    }

    /**
     * Deserialize cached response data
     *
     * @param array $data Serialized response data
     * @return ResponseInterface The reconstructed response
     */
    private function deserializeResponse(array $data): ResponseInterface
    {
        return new Response(
            $data['status'],
            $data['headers'],
            $data['body'],
            $data['protocol'],
            $data['reason']
        );
    }
}
