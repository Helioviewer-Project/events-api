<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Sources;

use Helioviewer\EventsApi\Utils\TimeRange;
use Helioviewer\EventsApi\Exception\SourceException;
use Psr\Http\Client\ClientInterface;
use GuzzleHttp\Client;

/**
 * Abstract base class for HTTP JSON-based solar event data sources.
 *
 * This class provides a specialized implementation of SourceInterface specifically
 * designed for data sources that communicate via HTTP and return JSON responses.
 * It implements the Template Method pattern to provide a consistent workflow for
 * JSON-based APIs while allowing concrete implementations to customize URL building
 * and response parsing logic.
 *
 * Key features:
 * - Standardized HTTP client configuration optimized for scientific APIs
 * - Robust JSON parsing with comprehensive error handling
 * - Consistent logging and debugging output across all sources
 * - Fault-tolerant design that gracefully handles API failures
 * - Template method structure ensuring uniform data retrieval patterns
 *
 * This class is particularly well-suited for modern REST APIs that return structured
 * JSON data, such as:
 * - CCMC/DONKI web services for solar event data
 * - HAPI (Heliophysics Application Programmer's Interface) endpoints
 * - NASA APIs for space weather and solar observations
 * - Third-party heliophysics data aggregation services
 *
 * Concrete implementations need only implement:
 * - buildUrl(): Construct API endpoint URLs with time range parameters
 * - extractDataFromResponse(): Parse JSON responses into event arrays
 * - getName(): Provide unique source identification
 *
 * @package    Helioviewer\EventsApi\Collector\Sources
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
abstract class JsonSource implements SourceInterface
{
    /**
     * Source identifier constants for different heliophysics data providers.
     * These constants provide standardized IDs for database storage and routing.
     */
    public const CCMC = 1;    // Community Coordinated Modeling Center
    public const HEK = 2;     // Heliophysics Event Knowledgebase
    public const WSA = 3;     // Wang-Sheeley-Arge model (HelioAnalytics dashboard)
    public const RHESSI = 4;  // Reuven Ramaty High Energy Solar Spectroscopic Imager

    /** Currently active sources — single source of truth for validation */
    public const VALID_SOURCES = ['CCMC', 'HEK', 'WSA', 'RHESSI'];

    /** Map source name to source_id */
    private const SOURCE_MAP = [
        'CCMC' => self::CCMC,
        'HEK' => self::HEK,
        'WSA' => self::WSA,
        'RHESSI' => self::RHESSI,
    ];

    public static function getSourceId(string $source): int
    {
        $name = strtoupper($source);
        if (!isset(self::SOURCE_MAP[$name])) {
            throw new \InvalidArgumentException("Unknown source: $source");
        }
        return self::SOURCE_MAP[$name];
    }

    /**
     * HTTP client for making API requests to JSON endpoints.
     *
     * @var ClientInterface PSR-18 compliant HTTP client configured for JSON API communication
     */
    protected ClientInterface $client;

    /**
     * Initialize the JSON data source with an optional HTTP client.
     *
     * Sets up the HTTP client for JSON API communication. The client is configured
     * with appropriate timeouts, headers, and error handling for scientific data APIs
     * that may have variable response times and require specific user agent identification.
     *
     * @param ClientInterface|null $client Optional HTTP client. If null, creates a default
     *                                    Guzzle client optimized for JSON API communication.
     */
    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? $this->createDefaultClient();
    }
    
    /**
     * Template method for fetching solar event data from JSON APIs.
     *
     * Implements the standard workflow for JSON-based data retrieval:
     * 1. Construct the API URL with time range parameters
     * 2. Execute HTTP request and parse JSON response
     * 3. Extract event data using source-specific parsing logic
     * 4. Return processed data or empty array on failure
     *
     * This template method ensures consistent behavior across all JSON sources
     * while allowing customization of URL building and response parsing through
     * abstract methods that concrete classes must implement.
     *
     * @param TimeRange $range The time range for which to fetch solar event data.
     *                         Will be converted to appropriate API parameters.
     *
     * @return array An array of raw event records from the JSON API. Each record
     *               is an associative array representing a single solar event.
     *               Returns empty array if no data found or errors occur.
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
     * Build the API URL with time range parameters for the specific JSON endpoint.
     *
     * Concrete implementations must construct the complete API URL including:
     * - Base endpoint URL for the specific data source
     * - Time range parameters in the format expected by the API
     * - Authentication tokens or API keys if required
     * - Additional query parameters (format, filters, etc.)
     *
     * Common JSON API patterns include:
     * - REST endpoints with query parameters: /api/events?start=2023-01-01&end=2023-01-02
     * - HAPI format: /hapi/data?id=dataset&time.min=2023-01-01T00:00:00&time.max=2023-01-02T00:00:00
     * - Custom parameter naming: /ws/get/FLR?startDate=2023-01-01&endDate=2023-01-02
     *
     * @param TimeRange $range The time range to include in the API request.
     *                         Implementations should convert timestamps to the
     *                         date/time format expected by their target API.
     *
     * @return string Complete API URL ready for HTTP GET request, including all
     *                necessary parameters for the time range query.
     */
    abstract protected function buildUrl(TimeRange $range): string;

    /**
     * Extract solar event data from the JSON API response.
     *
     * Parses the JSON response structure to locate and extract solar event records.
     * Different JSON APIs use various response formats:
     *
     * Direct array format (DONKI):
     * ```json
     * [{"flrID": "2023-01-01T12:00:00", "classType": "M1.2", ...}, ...]
     * ```
     *
     * HAPI format with metadata:
     * ```json
     * {
     *   "parameters": [{"name": "time", ...}, {"name": "value", ...}],
     *   "data": [["2023-01-01T12:00:00", 1.2], ...]
     * }
     * ```
     *
     * Nested data structure:
     * ```json
     * {"status": "success", "results": {"events": [{...}]}}
     * ```
     *
     * Implementations should:
     * - Navigate to the actual event data within the response structure
     * - Handle missing or null data gracefully
     * - Convert data types as needed for consistency
     * - Log parsing issues for debugging purposes
     * - Return empty array if no valid events are found
     *
     * @param array $jsonData The decoded JSON response as an associative array.
     *                       Structure varies by API but contains event information.
     *
     * @return array Array of parsed event records. Each element should be an
     *               associative array representing a single solar event with
     *               fields appropriate to the data source.
     */
    abstract protected function extractDataFromResponse(array $jsonData): array;
    
    /**
     * Execute HTTP GET request and decode JSON response.
     *
     * This method handles HTTP communication with JSON APIs:
     * - Makes GET requests with configurable timeouts
     * - Validates HTTP response codes and throws SourceException on 4xx/5xx errors
     * - Decodes JSON and throws SourceException on parsing errors
     * - Throws SourceException if response is not an array
     * - Lets network/timeout exceptions bubble up naturally
     *
     * @param string $url Complete API URL to request, including all query parameters.
     *                   Must be a valid HTTP/HTTPS URL for a JSON API endpoint.
     *
     * @return array The decoded JSON response as an associative array.
     * 
     * @throws SourceException When HTTP 4xx/5xx errors, JSON parsing errors, or non-array response
     * @throws \Psr\Http\Client\ClientExceptionInterface When network errors occur
     */
    protected function makeJsonRequest(string $url): array
    {
        // Make request - let network exceptions bubble up
        $response = $this->client->request('GET', $url);

        // Validate HTTP status - throw SourceException for HTTP errors
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new SourceException(
                "HTTP {$statusCode} error from API",
                $this->getName(),
                $url,
                $statusCode
            );
        }

        // Decode JSON response
        $rawResponse = $response->getBody()->getContents();

        // Sanitize invalid UTF-8 characters if needed
        if (!mb_check_encoding($rawResponse, 'UTF-8')) {
            // Try to detect encoding and convert, fallback to stripping invalid bytes
            $detected = mb_detect_encoding($rawResponse, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
            if ($detected && $detected !== 'UTF-8') {
                $rawResponse = mb_convert_encoding($rawResponse, 'UTF-8', $detected);
            } else {
                // Strip invalid UTF-8 sequences
                $rawResponse = iconv('UTF-8', 'UTF-8//IGNORE', $rawResponse);
            }
        }

        // Sanitize JavaScript-style values that are invalid JSON (NaN, Infinity, -Infinity)
        $rawResponse = $this->sanitizeJsonResponse($rawResponse);

        $jsonData = json_decode($rawResponse, true);

        // Validate JSON was parsed successfully - throw SourceException for parse errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new SourceException(
                "Invalid JSON response: " . json_last_error_msg(),
                $this->getName(),
                $url,
                0
            );
        }

        // Ensure response is an array - throw SourceException if not
        if (!is_array($jsonData)) {
            throw new SourceException(
                "JSON response is not an array, got: " . gettype($jsonData),
                $this->getName(),
                $url,
                0
            );
        }
        
        return $jsonData;
    }

    /**
     * Sanitize JavaScript-style values that are invalid in JSON.
     *
     * Some APIs (notably HEK) return JavaScript-style values like NaN, Infinity,
     * and -Infinity which are valid JavaScript but not valid JSON according to
     * RFC 8259. This method converts these values to null before JSON parsing.
     *
     * Handled cases:
     * - NaN (Not a Number) -> null
     * - Infinity (positive infinity) -> null
     * - -Infinity (negative infinity) -> null
     *
     * The regex uses lookbehind/lookahead to only match these values when they
     * appear as JSON values (after : or , or [) and not when they're part of
     * string content.
     *
     * @param string $response The raw JSON response string that may contain invalid values
     *
     * @return string The sanitized JSON string with NaN/Infinity replaced by null
     */
    protected function sanitizeJsonResponse(string $response): string
    {
        // Replace JavaScript NaN/Infinity with JSON null
        // Match only when they appear as values (after :, or [ with optional whitespace)
        // and are followed by whitespace and ,  } or ]
        return preg_replace(
            '/(?<=[:,\[])(\s*)(-?Infinity|NaN)(?=\s*[,}\]])/',
            '$1null',
            $response
        );
    }

    /**
     * Create default HTTP client optimized for JSON API communication.
     *
     * Configures a Guzzle HTTP client with settings specifically tuned for
     * JSON-based scientific APIs that may have the following characteristics:
     * - Variable response times due to data processing
     * - Large datasets requiring extended timeouts
     * - Rate limiting requiring user agent identification
     * - Multiple response formats (preferring JSON)
     *
     * Configuration details:
     * - 180-second timeout to handle slow scientific computing endpoints
     * - HTTP error exceptions enabled for proper PSR-18 compliance
     * - User-Agent header for API provider identification and rate limiting
     * - Accept header specifying JSON preference for APIs supporting multiple formats
     *
     * @return ClientInterface Configured PSR-18 HTTP client ready for JSON API requests
     *                         to heliophysics and space weather data sources.
     */
    protected function createDefaultClient(): ClientInterface
    {
        return new Client([
            // Extended timeout for scientific APIs that may process large datasets
            // (HEK JSON transfers can stall for minutes on the AWS→LMSAL path)
            'timeout' => 180.0,
            // Enable HTTP error exceptions for proper PSR-18 error handling
            'http_errors' => true,
            'headers' => [
                // Identify client for API providers (useful for rate limiting and support)
                'User-Agent' => 'Helioviewer Events API/1.0',
                // Request JSON format from APIs that support multiple response types
                'Accept' => 'application/json',
            ],
        ]);
    }
}
