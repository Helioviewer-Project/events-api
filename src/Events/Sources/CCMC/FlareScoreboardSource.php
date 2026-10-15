<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Sources\CCMC;

use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Utils\TimeRange;
use Psr\Http\Client\ClientInterface;

/**
 * Data source for FlareScoreboard solar flare prediction model data.
 *
 * FlareScoreboard is a NASA/GSFC initiative that provides real-time predictions
 * of solar flare probabilities using various machine learning and statistical models.
 * This data source accesses prediction data through the HAPI (Heliophysics Application
 * Programmer's Interface) protocol, which provides standardized access to time-series
 * heliophysics data with rich metadata descriptions.
 *
 * Key Features:
 * - Multiple prediction models with different methodologies and accuracy characteristics
 * - Probabilistic forecasts for different flare classes (C, M, X-class)
 * - Time-series prediction data with regular cadence (typically hourly)
 * - HAPI-compliant data format with parameter definitions and fill values
 * - Real-time and historical prediction archive access
 *
 * Supported Models:
 * The FlareScoreboard system includes various prediction models, each identified
 * by a unique model ID and name. Common models include:
 * - Statistical models based on active region properties
 * - Machine learning models using SDO/HMI magnetogram data
 * - Ensemble models combining multiple prediction approaches
 * - Physics-based models incorporating magnetic field evolution
 *
 * HAPI Data Format:
 * FlareScoreboard uses the HAPI protocol which structures data as:
 * - Parameters array: Defines data columns with names, types, units, and fill values
 * - Data array: Time-series values corresponding to parameter definitions
 * - Metadata: Information about the dataset, time ranges, and data provenance
 *
 * API Endpoint: https://ccmc.gsfc.nasa.gov/flare-scoreboard/hapi/data
 * Protocol: HAPI 3.0 (Heliophysics Application Programmer's Interface)
 * Time Format: ISO 8601 UTC with a Z suffix
 * Data Cadence: Typically hourly predictions
 *
 * Example HAPI Response:
 * ```json
 * {
 *   "parameters": [
 *     {"name": "time", "type": "isotime", "units": "UTC"},
 *     {"name": "c_class_prob", "type": "double", "units": "probability", "fill": -1e31},
 *     {"name": "m_class_prob", "type": "double", "units": "probability", "fill": -1e31}
 *   ],
 *   "data": [
 *     ["2023-01-01T12:00:00.000", 0.15, 0.03],
 *     ["2023-01-01T13:00:00.000", 0.18, 0.04]
 *   ]
 * }
 * ```
 *
 * @package    Helioviewer\EventsApi\Collector\Sources\CCMC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 * @link       https://ccmc.gsfc.nasa.gov/flare-scoreboard/hapi/ FlareScoreboard HAPI server
 * @link       https://hapi-server.org/ HAPI Protocol Specification
 */
class FlareScoreboardSource extends JsonSource
{
    /**
     * Initialize FlareScoreboard source for a specific prediction model.
     *
     * Each FlareScoreboard instance is configured for a specific prediction model
     * identified by its unique model ID and descriptive name. This allows the
     * system to collect data from multiple models simultaneously while maintaining
     * clear identification of the prediction source.
     *
     * Model Configuration:
     * - Model ID: Unique identifier used in HAPI API requests
     * - Model Name: Human-readable description for logging and identification
     * - HTTP Client: Optional client for customized request handling
     *
     * @param string                   $modelId   Unique identifier for the prediction model
     *                                           as used in HAPI API requests (e.g., "model_001").
     * @param string                   $modelName Descriptive name of the prediction model
     *                                           for logging and identification purposes.
     * @param ClientInterface|null     $client    Optional HTTP client. If null, a default
     *                                           client optimized for HAPI requests is created.
     */
    public function __construct(
        private string $modelId,
        private string $modelName,
        ?ClientInterface $client = null
    ) {
        parent::__construct($client);
    }
    
    /**
     * Get the unique identifier for this FlareScoreboard model data source.
     *
     * Constructs a unique source identifier by combining the base FlareScoreboard
     * prefix with the specific model ID. This ensures that each prediction model
     * is treated as a distinct data source in the collection pipeline, enabling
     * parallel processing and separate storage of different model predictions.
     *
     * @return string Unique source identifier in format 'FLARE_SCOREBOARD_<modelId>'.
     */
    public function getName(): string
    {
        return 'FLARE_SCOREBOARD_' . $this->modelId;
    }

    /**
     * Get the descriptive name of the prediction model.
     *
     * Returns the human-readable model name for use in logging, error messages,
     * and user interfaces. This provides context about which specific prediction
     * algorithm or methodology is being used.
     *
     * @return string The descriptive name of the prediction model (e.g.,
     *                "Statistical AR Model", "Deep Learning CNN Model").
     */
    public function getModelName(): string
    {
        return $this->modelName;
    }
    
    /**
     * Build the HAPI API URL for FlareScoreboard prediction data.
     *
     * Constructs the HAPI 3.0 data URL for one model and time range:
     * https://ccmc.gsfc.nasa.gov/flare-scoreboard/hapi/data?dataset=…&start=…Z&stop=…Z&format=json
     * Times must end in Z (the server rejects them otherwise); the dataset id is
     * URL-encoded, since some contain spaces ("MetOffice - Solar Region Summary_REGIONS").
     *
     * @param TimeRange $range The time range for prediction data query.
     *                         Unix timestamps are converted to ISO 8601 format.
     *
     * @return string Complete HAPI API URL with model ID and time range parameters.
     */
    protected function buildUrl(TimeRange $range): string
    {
        return 'https://ccmc.gsfc.nasa.gov/flare-scoreboard/hapi/data?' . http_build_query([
            'dataset' => $this->modelId,
            'start'   => gmdate('Y-m-d\TH:i:s\Z', $range->start),
            'stop'    => gmdate('Y-m-d\TH:i:s\Z', $range->end),
            'format'  => 'json',
        ]);
    }
    
    /**
     * Extract the unique remote ID from a FlareScoreboard raw record.
     *
     * One prediction = one model, forecast window and region: start_window,
     * end_window and the NOAA region number, else the model's own region number
     * (the source name is prefixed by the collector). The issue time is left
     * out, so a re-issued forecast for the same window updates the event; a
     * content hash would store every upstream change as a new event.
     *
     * @param array $rawRecord The raw prediction record (fills already null)
     *
     * @return string e.g. "2026-10-14T00:00:00.000Z:2026-10-15T00:00:00.000Z:NOAA14548"
     */
    public function extractRawRecordId(array $rawRecord): string
    {
        $region = isset($rawRecord['NOAARegionId']) ? 'NOAA' . $rawRecord['NOAARegionId']
            : (isset($rawRecord['ModelRegionId']) ? 'Model' . $rawRecord['ModelRegionId'] : 'none');

        return "{$rawRecord['start_window']}:{$rawRecord['end_window']}:{$region}";
    }
    
    /**
     * Extract prediction data from FlareScoreboard HAPI JSON response.
     *
     * FlareScoreboard uses the HAPI (Heliophysics Application Programmer's Interface)
     * protocol, which structures data with separate parameter definitions and data arrays.
     * This method handles the HAPI format extraction and transformation into structured
     * records suitable for downstream processing.
     *
     * HAPI Response Structure:
     * ```json
     * {
     *   "parameters": [
     *     {"name": "time", "type": "isotime", "units": "UTC"},
     *     {"name": "c_prob", "type": "double", "fill": -1e31}
     *   ],
     *   "data": [
     *     ["2023-01-01T12:00:00.000", 0.15],
     *     ["2023-01-01T13:00:00.000", 0.18]
     *   ]
     * }
     * ```
     *
     * Processing Steps:
     * 1. Locate the 'data' array within the HAPI response structure
     * 2. Handle fallback cases for direct array responses
     * 3. Extract parameter definitions for data column mapping
     * 4. Transform raw data arrays into structured associative arrays
     * 5. Handle HAPI fill values and data type conversions
     *
     * Error Handling:
     * - Missing 'data' array: Log warning and return empty array
     * - Unexpected structure: Log details for debugging
     * - Invalid parameter mappings: Skip problematic records
     *
     * @param array $jsonData The complete HAPI JSON response including both
     *                       'parameters' metadata and 'data' arrays.
     *
     * @return array Array of structured prediction records. Each record is an
     *               associative array with parameter names as keys and prediction
     *               values appropriately typed and with fill values converted to null.
     */
    protected function extractDataFromResponse(array $jsonData): array
    {
        // HAPI format nests actual data within a 'data' array
        $rawData = [];

        if (isset($jsonData['data']) && is_array($jsonData['data'])) {
            $rawData = $jsonData['data'];
        } else if (is_array($jsonData)) {
            // Fallback: Handle direct array responses (non-standard HAPI)
            $rawData = $jsonData;
        } else {
            echo "WARNING: FlareScoreboard response has unexpected structure\n";
            error_log("FlareScoreboard response missing 'data' array: " . json_encode(array_keys($jsonData)));
            return [];
        }

        // Transform HAPI format data into structured associative arrays
        $records = $this->readHapi($jsonData, $rawData);

        // Rows with no region and no position anywhere can never be placed (SIDC's
        // Catania copies of a NOAA row); drop them here rather than fail them one by one
        return array_values(array_filter($records, fn(array $r) => !self::isEmptyRegionRow($r)));
    }
    
    /**
     * Parse HAPI format data into structured associative arrays.
     *
     * The HAPI (Heliophysics Application Programmer's Interface) protocol separates
     * data structure definitions from actual data values. This method combines the
     * parameter metadata with data arrays to create meaningful associative arrays
     * suitable for further processing and storage.
     *
     * HAPI Data Structure:
     * - Parameters: Array defining column names, types, units, and fill values
     * - Data: Array of arrays containing the actual time-series values
     * - Each data row corresponds to the parameter definitions by index
     *
     * Processing Logic:
     * 1. Extract parameter definitions from the HAPI response
     * 2. Map each data array element to corresponding parameter names
     * 3. Handle HAPI fill values by converting them to null
     * 4. Preserve data types and units as defined in parameters
     * 5. Skip malformed records and log processing statistics
     *
     * Fill Value Handling:
     * HAPI uses fill values to indicate missing or invalid data points.
     * Common fill values include -1e31, NaN, or custom values specified
     * in the parameter definition. These are converted to null for
     * consistent handling in the application.
     *
     * Example Transformation:
     * Input:
     * ```
     * parameters: [{"name": "time"}, {"name": "prob", "fill": -1e31}]
     * data: [["2023-01-01T12:00:00", 0.15], ["2023-01-01T13:00:00", -1e31]]
     * ```
     * Output:
     * ```
     * [
     *   {"time": "2023-01-01T12:00:00", "prob": 0.15},
     *   {"time": "2023-01-01T13:00:00", "prob": null}
     * ]
     * ```
     *
     * @param array $fullResponse The complete HAPI response containing both
     *                           'parameters' and 'data' sections.
     * @param array $dataArray    The extracted data array from the HAPI response,
     *                           containing arrays of values corresponding to parameters.
     *
     * @return array Array of structured records where each record is an associative
     *               array with parameter names as keys and appropriately typed values.
     *               Fill values are converted to null for consistent data handling.
     */
    private function readHapi(array $fullResponse, array $dataArray): array
    {
        // Extract parameter definitions from HAPI response
        $parameters = $fullResponse['parameters'] ?? [];

        if (empty($parameters)) {
            // No parameter metadata available - return raw data as-is
            return $dataArray;
        }

        $processedRecords = [];

        // Process each data record using parameter definitions
        foreach ($dataArray as $dataRecord) {
            if (!is_array($dataRecord)) {
                // Skip non-array records (malformed data)
                continue;
            }

            $processedRecord = [];

            // Map each data value to its corresponding parameter name
            foreach ($parameters as $index => $parameter) {
                $paramName = $parameter['name'] ?? "param_$index";
                $fillValue = $parameter['fill'] ?? null;

                $value = $dataRecord[$index] ?? null;

                // Fill values mean "no value". CCMC declares them as strings ("-1",
                // "999") but often sends numbers (-1, 999), so compare loosely.
                if ($fillValue !== null && $value !== null && self::isFill($value, $fillValue)) {
                    $processedRecord[$paramName] = null;
                } else {
                    $processedRecord[$paramName] = $value;
                }
            }

            $processedRecords[] = $processedRecord;
        }


        return $processedRecords;
    }

    private static function isFill(mixed $value, mixed $fill): bool
    {
        if ((string) $value === (string) $fill) {
            return true;
        }

        return is_numeric($value) && is_numeric($fill) && (float) $value === (float) $fill;
    }

    private static function isEmptyRegionRow(array $record): bool
    {
        foreach (['NOAA', 'Catania', 'Model'] as $prefix) {
            foreach (['RegionId', 'LocationTime', 'Latitude', 'Longitude'] as $field) {
                if (isset($record[$prefix . $field])) {
                    return false;
                }
            }
        }

        return true;
    }
}
