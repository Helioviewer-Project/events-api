<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Sources\CCMC;

use Helioviewer\EventsApi\Sources\JsonSource;
use Helioviewer\EventsApi\Utils\TimeRange;

/**
 * Data source for DONKI (Database Of Notifications, Knowledge, Information) CME events.
 *
 * This class provides access to Coronal Mass Ejection (CME) event data from NASA's
 * CCMC DONKI web service, which serves as the authoritative database for space weather
 * events and their Earth-directed impact analysis. DONKI aggregates CME observations
 * from multiple coronagraph instruments and provides comprehensive event characterization
 * including kinematics, geometry, and Earth impact predictions.
 *
 * The DONKI CME web service provides:
 * - CME events observed by SOHO/LASCO and STEREO coronagraphs
 * - Kinematic analysis including speed, acceleration, and trajectory
 * - CME geometric properties (angular width, central position angle)
 * - Earth-directed CME identification and impact time predictions
 * - Associated solar flare events and active region sources
 * - Shock arrival predictions and geomagnetic storm forecasts
 * - Historical archive and real-time event notifications
 *
 * CME Event Characterization:
 * - Detection from coronagraph difference images
 * - Manual and automated catalog entries with quality assessment
 * - Speed measurements from height-time analysis
 * - Angular width and position angle from coronagraph imagery
 * - Source region identification using EUV observations
 * - Earth impact modeling using ensemble propagation techniques
 *
 * API Endpoint: https://kauai.ccmc.gsfc.nasa.gov/DONKI/WS/get/CME
 * Data Format: Direct JSON array of CME event objects
 * Time Range: Supports date-based queries with startDate/endDate parameters
 * Update Frequency: Near real-time with retrospective quality improvements
 *
 * Example API Response:
 * ```json
 * [
 *   {
 *     "activityID": "2023-01-01T15:30:00-CME-001",
 *     "catalog": "M2M_CATALOG",
 *     "startTime": "2023-01-01T15:30:00Z",
 *     "sourceLocation": "N15W30",
 *     "activeRegionNum": 3182,
 *     "note": "Earth-directed CME with estimated speed 800 km/s",
 *     "cmeAnalyses": [
 *       {
 *         "time21_5": "2023-01-01T16:00:00Z",
 *         "latitude": 15,
 *         "longitude": -30,
 *         "halfAngle": 25,
 *         "speed": 800,
 *         "type": "C",
 *         "isMostAccurate": true
 *       }
 *     ]
 *   }
 * ]
 * ```
 *
 * @package    Helioviewer\EventsApi\Sources\CCMC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 * @link       https://ccmc.gsfc.nasa.gov/tools/DONKI/ DONKI Database
 * @link       https://kauai.ccmc.gsfc.nasa.gov/DONKI/WS/get/CME DONKI CME API
 */
class DonkiCmeSource extends JsonSource
{
    /**
     * Get the unique identifier for this DONKI CME data source.
     *
     * Returns a standardized identifier used throughout the Helioviewer Events API
     * for logging, database storage, and source routing. This identifier distinguishes
     * DONKI CME data from other solar event sources including DONKI flare events
     * and other CME databases.
     *
     * @return string The unique source identifier 'DONKI_CME' for this data source.
     */
    public function getName(): string
    {
        return 'DONKI_CME';
    }
    
    /**
     * Build the DONKI CME API URL with time range parameters.
     *
     * Constructs the complete API endpoint URL for querying DONKI CME data
     * within the specified time range. The DONKI API uses simple date parameters
     * in YYYY-MM-DD format to constrain the query period for CME events.
     *
     * URL Structure:
     * - Base: https://kauai.ccmc.gsfc.nasa.gov/DONKI/WS/get/CME
     * - Parameters: startDate and endDate in YYYY-MM-DD format
     * - Example: /CME?startDate=2023-01-01&endDate=2023-01-31
     *
     * The API returns all CME events that were first observed between the start
     * and end dates (inclusive). Note that CME events may have extended temporal
     * evolution with multiple analysis entries, but the date filter applies to
     * the initial detection time.
     *
     * Time Handling:
     * - Query dates are based on CME first observation time
     * - All returned timestamps are in UTC
     * - CME analyses may span multiple days from initial detection
     * - Impact predictions extend beyond the query time range
     *
     * @param TimeRange $range The time range for the CME data query. Start and end
     *                         timestamps are converted to YYYY-MM-DD format for
     *                         the DONKI API parameters.
     *
     * @return string Complete DONKI CME API URL ready for HTTP GET request,
     *                including startDate and endDate query parameters.
     */
    protected function buildUrl(TimeRange $range): string
    {
        $startDate = $range->getStartDate();
        $endDate = $range->getEndDate();

        return "https://kauai.ccmc.gsfc.nasa.gov/DONKI/WS/get/CME?"
             . "startDate=" . $startDate
             . "&endDate=" . $endDate;
    }
    
    /**
     * Extract the unique remote ID from a DONKI CME raw record.
     *
     * DONKI CME events use the 'activityID' field as their unique identifier.
     * This field contains timestamped identifiers in the format:
     * "YYYY-MM-DDTHH:MM:SS-CME-###" (e.g., "2023-01-01T15:30:00-CME-001")
     *
     * @param array $rawRecord The raw CME event record from DONKI API
     *
     * @return string The unique activity ID for this CME event
     *
     * @throws \InvalidArgumentException When activityID is missing or empty
     */
    public function extractRawRecordId(array $rawRecord): string
    {
        if (!isset($rawRecord['activityID']) || empty($rawRecord['activityID'])) {
            throw new \InvalidArgumentException('DONKI CME record missing required activityID field');
        }

        return (string) $rawRecord['activityID'];
    }

    /**
     * Extract CME events from the DONKI API JSON response.
     *
     * DONKI CME API uses the same straightforward response format as other DONKI
     * endpoints, returning CME events as a direct JSON array without nested metadata
     * structures. Each array element represents a single CME event with comprehensive
     * observational data and kinematic analysis results.
     *
     * Response Structure:
     * - Direct array of CME event objects (no wrapper or pagination)
     * - Each event contains basic metadata and nested analysis arrays
     * - All timestamps in ISO 8601 format with UTC timezone
     * - Coordinates in heliographic format for source locations
     * - Multiple analysis entries per event from different measurement techniques
     * - Speed measurements in km/s, angles in degrees
     *
     * Key Event Fields:
     * - activityID: Unique identifier for the CME event
     * - catalog: Source catalog (e.g., "M2M_CATALOG", "JANG_CATALOG")
     * - startTime: Initial detection time in UTC
     * - sourceLocation: Heliographic coordinates of source region
     * - activeRegionNum: Associated solar active region number
     * - cmeAnalyses: Array of kinematic and geometric analysis results
     * - note: Human-readable description and impact assessment
     *
     * CME Analysis Sub-structure:
     * Each CME event contains nested analysis objects with measurements:
     * - time21_5: Measurement time at 21.5 solar radii
     * - latitude/longitude: CME propagation direction
     * - halfAngle: Angular width of the CME
     * - speed: Projected speed in km/s
     * - type: Analysis type ("C" for Computer, "M" for Manual)
     * - isMostAccurate: Flag indicating preferred analysis
     *
     * No additional parsing or transformation is required since DONKI provides
     * well-structured data that can be directly processed by downstream components.
     * The nested cmeAnalyses arrays are preserved to maintain full kinematic detail.
     *
     * @param array $jsonData The decoded JSON response from DONKI CME API containing
     *                       a direct array of CME event objects with nested analyses.
     *
     * @return array Array of CME event records as returned by DONKI. Each record
     *               is an associative array with CME metadata and nested analysis
     *               arrays containing kinematic measurements. Returns input array
     *               unchanged since DONKI provides clean, well-formatted data.
     */
    protected function extractDataFromResponse(array $jsonData): array
    {
        // DONKI returns a direct array of CME events with no nested structure
        // No additional parsing or transformation needed
        $cleanData = $jsonData;

        echo "DEBUG: DONKI CME returned " . count($cleanData) . " raw events\n";

        return $cleanData;
    }
}
