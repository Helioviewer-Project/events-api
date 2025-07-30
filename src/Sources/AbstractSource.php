<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Sources;

use Helioviewer\EventsApi\Utils\TimeRange;
use Psr\Http\Client\ClientInterface;
use GuzzleHttp\Client;

/**
 * Abstract base class for HTTP-based solar event data sources.
 *
 * This abstract class provides common functionality for data sources that retrieve
 * solar event information via HTTP APIs. It implements the Template Method pattern
 * to define a consistent data retrieval workflow while allowing concrete implementations
 * to customize source-specific behaviors such as URL construction and response parsing.
 *
 * The class handles:
 * - HTTP client configuration with appropriate timeouts and headers
 * - Common error handling patterns for network requests
 * - JSON response parsing and validation
 * - Logging and debugging output for request tracking
 * - Template method structure for consistent data fetching workflow
 *
 * Concrete implementations must provide:
 * - URL building logic specific to their API endpoints
 * - Response parsing logic tailored to their data formats
 * - Source identification via getName() method
 *
 * This class supports various heliophysics data sources including CCMC/DONKI,
 * HEK, WSA, and RHESSI, each with different API patterns and data structures.
 *
 * @package    Helioviewer\EventsApi\Sources
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
abstract class AbstractSource implements SourceInterface
{
    /**
     * Source identifier constants for different heliophysics data providers.
     * These constants provide standardized IDs for database storage and routing.
     */
    public const CCMC = 1;    // Community Coordinated Modeling Center
    public const HEK = 2;     // Heliophysics Event Knowledgebase
    public const WSA = 3;     // Wang-Sheeley-Arge model
    public const RHESSI = 4;  // Reuven Ramaty High Energy Solar Spectroscopic Imager

    /**
     * HTTP client for making API requests.
     *
     * @var ClientInterface PSR-18 compliant HTTP client for external API communication
     */
    protected ClientInterface $client;

    /**
     * Initialize the data source with an optional HTTP client.
     *
     * Sets up the HTTP client for API communication. If no client is provided,
     * a default Guzzle client will be created with appropriate configuration
     * for heliophysics data source APIs.
     *
     * @param ClientInterface|null $client Optional HTTP client. If null, a default
     *                                    client with standard configuration will be created.
     */
    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? $this->createDefaultClient();
    }
    
    /**
     * Build the API URL for fetching data within the specified time range.
     *
     * This abstract method must be implemented by concrete classes to construct
     * the appropriate API endpoint URL for their specific data source. The URL
     * should include all necessary parameters such as:
     * - Time range constraints (start/end dates)
     * - API keys or authentication tokens
     * - Data format specifications
     * - Any source-specific filters or options
     *
     * Different APIs use various date formats and parameter naming conventions:
     * - DONKI: Uses YYYY-MM-DD format with startDate/endDate parameters
     * - HAPI: Uses ISO 8601 format with time.min/time.max parameters
     * - HEK: Uses custom timestamp formats with specific parameter names
     *
     * @param TimeRange $range The time range for which data should be fetched.
     *                         Implementations should convert this to the appropriate
     *                         format expected by their target API.
     *
     * @return string The complete API URL ready for HTTP GET request, including
     *                all necessary query parameters and authentication.
     */
    abstract protected function buildUrl(TimeRange $range): string;

    /**
     * Extract and parse solar event data from the API's JSON response.
     *
     * This abstract method handles source-specific response parsing logic.
     * Different APIs return data in various formats:
     * - Direct arrays of event objects (DONKI)
     * - Nested structures with metadata (HAPI format)
     * - Paginated responses with continuation tokens
     * - Hierarchical data requiring flattening
     *
     * The method should:
     * - Navigate the response structure to locate event data
     * - Handle missing or null data gracefully
     * - Convert data types as needed (strings to numbers, date formats)
     * - Filter out invalid or incomplete records
     * - Log any parsing issues for debugging
     *
     * @param array $jsonData The decoded JSON response from the API as an associative array.
     *                       Structure varies by data source but should contain event information.
     *
     * @return array An array of parsed event records. Each element should be an associative
     *               array representing a single solar event with consistent field naming
     *               within the source (though field names may vary between sources).
     */
    abstract protected function extractDataFromResponse(array $jsonData): array;
    
    /**
     * Template method for fetching solar event data - defines the overall workflow.
     *
     * This method implements the Template Method pattern, providing a consistent
     * data retrieval workflow while allowing concrete implementations to customize
     * specific steps. The workflow follows these steps:
     * 1. Build the API URL using the time range
     * 2. Make the HTTP request and parse JSON response
     * 3. Extract event data using source-specific parsing logic
     * 4. Return the processed data or empty array on failure
     *
     * All error handling, logging, and HTTP client management is handled by this
     * template method, ensuring consistent behavior across all data sources.
     *
     * @param TimeRange $range The time range for which to fetch solar event data.
     *                         Must contain valid start and end timestamps.
     *
     * @return array An array of raw event records from the data source. Returns
     *               empty array if no data is available or if errors occur during
     *               the request/parsing process.
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface When HTTP client encounters network errors
     */
    public function fetchRawData(TimeRange $range): array
    {
        $url = $this->buildUrl($range);
        $jsonData = $this->makeJsonRequest($url);

        if (empty($jsonData)) {
            return [];
        }

        return $this->extractDataFromResponse($jsonData);
    }
    
    /**
     * Execute HTTP request and handle JSON decoding with comprehensive error handling.
     *
     * This method handles all aspects of HTTP communication with external APIs:
     * - Makes GET requests with proper error handling
     * - Validates HTTP status codes and handles API errors
     * - Decodes JSON responses with validation
     * - Provides detailed logging for debugging and monitoring
     * - Returns empty arrays on any failure to maintain consistent behavior
     *
     * The method is designed to be fault-tolerant, logging errors but not throwing
     * exceptions to prevent single source failures from disrupting the entire
     * data collection pipeline.
     *
     * @param string $url The complete API URL to request, including all parameters
     *                   and authentication. Should be a valid HTTP/HTTPS URL.
     *
     * @return array The decoded JSON response as an associative array. Returns
     *               empty array if the request fails, returns non-200 status,
     *               or contains invalid JSON. Never returns null or scalar values.
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface When HTTP client encounters
     *                                                   unrecoverable network errors
     */
    protected function makeJsonRequest(string $url): array
    {
        echo "DEBUG: Requesting " . $this->getName() . " URL: " . $url . "\n";

        try {
            $response = $this->client->request('GET', $url);

            // Validate HTTP status code - API errors should be handled gracefully
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                echo "ERROR: HTTP {$statusCode} from {$url}\n";
                error_log("HTTP {$statusCode} from {$url}");
                return [];
            }

            // Decode JSON response with error checking
            $rawResponse = $response->getBody()->getContents();
            $jsonData = json_decode($rawResponse, true);

            // Validate JSON parsing was successful
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Invalid JSON response from {$url}: " . json_last_error_msg());
                return [];
            }

            // Ensure we always return an array for consistent processing
            return is_array($jsonData) ? $jsonData : [];
        } catch (\Exception $e) {
            error_log("Failed to fetch " . $this->getName() . " data from {$url}: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create a default HTTP client with optimized configuration for heliophysics APIs.
     *
     * Configures a Guzzle HTTP client with settings appropriate for scientific
     * data APIs that may have variable response times and large datasets:
     * - Extended timeout for slow scientific computing endpoints
     * - Appropriate User-Agent for identification and rate limiting
     * - JSON content type preference for API responses
     * - Error handling enabled for proper exception management
     *
     * The configuration is designed to work reliably with government and academic
     * APIs that may have different performance characteristics than commercial APIs.
     *
     * @return ClientInterface A configured PSR-18 HTTP client ready for API communication
     *                         with heliophysics data sources.
     */
    protected function createDefaultClient(): ClientInterface
    {
        return new Client([
            // Extended timeout for scientific APIs that may process large datasets
            'timeout' => 60.0,
            // Enable HTTP error exceptions for proper error handling
            'http_errors' => true,
            'headers' => [
                // Identify the client for API providers and rate limiting
                'User-Agent' => 'Helioviewer Events API/1.0',
                // Request JSON responses from APIs that support multiple formats
                'Accept' => 'application/json',
            ],
        ]);
    }
}