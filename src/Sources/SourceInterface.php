<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Sources;

use Helioviewer\EventsApi\Utils\TimeRange;

/**
 * Interface for all solar event data sources in the Helioviewer Events API.
 *
 * This interface defines the contract that all data source implementations must follow
 * to integrate with the unified solar event collection system. Data sources can include
 * various heliophysics databases and APIs such as HEK (Heliophysics Event Knowledgebase),
 * CCMC/DONKI (Community Coordinated Modeling Center), FlareScoreboard prediction models,
 * WSA (Wang-Sheeley-Arge), and RHESSI (Reuven Ramaty High Energy Solar Spectroscopic Imager).
 *
 * Each implementation is responsible for:
 * - Connecting to external APIs or data sources
 * - Handling authentication and rate limiting as needed
 * - Converting time ranges to source-specific formats
 * - Parsing and normalizing response data
 * - Providing error handling and fallback strategies
 *
 * The interface supports a unified data collection pipeline where multiple sources
 * can be queried simultaneously for comprehensive solar event coverage across
 * different time ranges and event types.
 *
 * @package    Helioviewer\EventsApi\Sources
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
interface SourceInterface
{
    /**
     * Fetch raw data from the source for the specified time range.
     *
     * This method is responsible for retrieving solar event data from the external
     * data source within the given time boundaries. Implementations should handle
     * all aspects of data retrieval including:
     * - Building appropriate API requests with time range parameters
     * - Managing HTTP connections and timeouts
     * - Handling API-specific authentication or rate limiting
     * - Parsing response data into normalized array format
     * - Logging errors and implementing fallback strategies
     *
     * The returned array should contain raw event records as associative arrays,
     * with each record representing a single solar event. The exact structure
     * will vary by source but should be consistent within each implementation.
     *
     * @param TimeRange $range The time range for which to fetch solar event data.
     *                         Both start and end times will be used to constrain
     *                         the query to the external data source.
     *
     * @return array An array of raw event records from the data source. Each element
     *               should be an associative array representing a single solar event.
     *               Returns an empty array if no data is found or if an error occurs.
     *
     * @throws \Psr\Http\Client\ClientExceptionInterface When HTTP client encounters network errors
     * @throws \InvalidArgumentException When the time range is invalid or unsupported
     * @throws \RuntimeException When the data source is unavailable or returns invalid data
     */
    public function fetchRawData(TimeRange $range): array;

    /**
     * Get the unique identifier name for this data source.
     *
     * This method returns a unique string identifier that distinguishes this
     * data source from others in the system. The name is used for:
     * - Logging and debugging purposes
     * - Database source identification
     * - Configuration and routing
     * - Error reporting and monitoring
     *
     * The identifier should be:
     * - Unique across all source implementations
     * - Descriptive of the data source (e.g., 'DONKI_FLARE', 'HEK_ACTIVE_REGIONS')
     * - Stable across application restarts and deployments
     * - Safe for use in file names, URLs, and database keys
     *
     * @return string A unique, stable identifier for this data source implementation.
     *                Must be non-empty and should follow UPPER_SNAKE_CASE convention.
     */
    public function getName(): string;

    /**
     * Extract the unique remote ID from a raw record.
     *
     * This method extracts the unique identifier from a raw event record as returned
     * by the data source's API. This ID is used for event deduplication and tracking
     * across different ingestion cycles.
     *
     * Different sources use various field names for their unique identifiers:
     * - DONKI: 'activityID' field containing timestamped identifiers
     * - HEK: 'kb_archivid' or similar unique record identifiers
     * - FlareScoreboard: Composite IDs based on model and prediction time
     * - WSA: Model run identifiers with timestamp components
     *
     * The extracted ID should be:
     * - Unique within the data source
     * - Stable across API calls for the same event
     * - Suitable for use as a database key
     * - Non-empty string value
     *
     * @param array $rawRecord The raw event record as returned by fetchRawData(),
     *                        containing all fields from the source API response.
     *
     * @return string The unique remote identifier for this event record.
     *                Must be non-empty and stable for the same event.
     *
     * @throws \InvalidArgumentException When the record lacks a valid unique identifier
     * @throws \RuntimeException When the identifier cannot be extracted or is malformed
     */
    public function extractRawRecordId(array $rawRecord): string;
}