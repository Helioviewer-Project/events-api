<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Sources\CCMC;

use Helioviewer\EventsApi\Sources\JsonSource;
use Helioviewer\EventsApi\Utils\TimeRange;

/**
 * Data source for DONKI (Database Of Notifications, Knowledge, Information) Solar Flare events.
 *
 * This class provides access to solar flare event data from NASA's CCMC DONKI web service,
 * which serves as a comprehensive database for space weather events and their impact analysis.
 * DONKI aggregates solar flare observations from multiple instruments and provides standardized
 * event characterization including timing, location, classification, and associated phenomena.
 *
 * The DONKI Solar Flare web service provides:
 * - Solar flare events from multiple observing instruments (GOES, SDO/EVE, etc.)
 * - GOES X-ray flux classification (A, B, C, M, X class flares)
 * - Peak flux measurements and timing information
 * - Heliographic coordinates of flare locations
 * - Associated coronal mass ejections and other phenomena
 * - Real-time and historical data access
 *
 * API Endpoint: https://kauai.ccmc.gsfc.nasa.gov/DONKI/WS/get/FLR
 * Data Format: Direct JSON array of flare event objects
 * Time Range: Supports date-based queries with startDate/endDate parameters
 * Rate Limits: Reasonable usage expected for research purposes
 *
 * Example API Response:
 * ```json
 * [
 *   {
 *     "flrID": "2023-01-01T12:00:00-FLR-001",
 *     "instruments": [{"displayName": "GOES-16"}],
 *     "beginTime": "2023-01-01T12:00:00Z",
 *     "peakTime": "2023-01-01T12:05:00Z",
 *     "endTime": "2023-01-01T12:10:00Z",
 *     "classType": "M1.2",
 *     "sourceLocation": "N15W30",
 *     "activeRegionNum": 3182
 *   }
 * ]
 * ```
 *
 * @package    Helioviewer\EventsApi\Sources\CCMC
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 * @link       https://ccmc.gsfc.nasa.gov/tools/DONKI/ DONKI Database
 * @link       https://kauai.ccmc.gsfc.nasa.gov/DONKI/WS/get/FLR DONKI Flare API
 */
class DonkiFlareSource extends JsonSource
{
    /**
     * Get the unique identifier for this DONKI Solar Flare data source.
     *
     * Returns a standardized identifier used throughout the Helioviewer Events API
     * for logging, database storage, and source routing. This identifier distinguishes
     * DONKI flare data from other solar event sources in the system.
     *
     * @return string The unique source identifier 'DONKI_FLARE' used for this data source.
     */
    public function getName(): string
    {
        return 'DONKI_FLARE';
    }
    
    /**
     * Build the DONKI Solar Flare API URL with time range parameters.
     *
     * Constructs the complete API endpoint URL for querying DONKI solar flare data
     * within the specified time range. The DONKI API uses simple date parameters
     * in YYYY-MM-DD format to constrain the query period.
     *
     * URL Structure:
     * - Base: https://kauai.ccmc.gsfc.nasa.gov/DONKI/WS/get/FLR
     * - Parameters: startDate and endDate in YYYY-MM-DD format
     * - Example: /FLR?startDate=2023-01-01&endDate=2023-01-31
     *
     * The API returns all solar flare events that occurred between the start and end
     * dates (inclusive). The DONKI service automatically handles timezone conversion
     * and returns events in UTC timestamps.
     *
     * @param TimeRange $range The time range for the flare data query. The start and
     *                         end timestamps will be converted to YYYY-MM-DD format
     *                         for the DONKI API parameters.
     *
     * @return string Complete DONKI API URL ready for HTTP GET request, including
     *                startDate and endDate query parameters.
     */
    protected function buildUrl(TimeRange $range): string
    {
        $startDate = $range->getStartDate();
        $endDate = $range->getEndDate();

        return "https://kauai.ccmc.gsfc.nasa.gov/DONKI/WS/get/FLR?"
             . "startDate=" . $startDate
             . "&endDate=" . $endDate;
    }
    
    /**
     * Extract solar flare events from the DONKI API JSON response.
     *
     * DONKI uses a straightforward response format that returns solar flare events
     * as a direct JSON array, unlike some APIs that nest data within metadata structures.
     * Each array element represents a single solar flare event with comprehensive
     * observational data and derived parameters.
     *
     * Response Structure:
     * - Direct array of flare event objects (no wrapper or metadata)
     * - Each event contains timing, classification, location, and instrument data
     * - All timestamps are in ISO 8601 format with UTC timezone
     * - Coordinates use standard heliographic notation (e.g., "N15W30")
     * - GOES classification provided as string (e.g., "M1.2", "X2.1")
     *
     * Key Event Fields:
     * - flrID: Unique identifier for the flare event
     * - beginTime/peakTime/endTime: Event timing in UTC
     * - classType: GOES X-ray classification
     * - sourceLocation: Heliographic coordinates
     * - activeRegionNum: Associated active region number
     * - instruments: Array of observing instruments
     *
     * No additional parsing or transformation is required since DONKI provides
     * well-structured data that can be directly processed by downstream components.
     *
     * @param array $jsonData The decoded JSON response from DONKI API containing
     *                       a direct array of solar flare event objects.
     *
     * @return array Array of solar flare event records as returned by DONKI.
     *               Each record is an associative array with flare parameters
     *               and observational data. Returns the input array unchanged
     *               since DONKI provides clean, well-formatted data.
     */
    protected function extractDataFromResponse(array $jsonData): array
    {
        // DONKI returns a direct array of events with no nested structure
        // No additional parsing or transformation needed
        $cleanData = $jsonData;

        echo "DEBUG: DONKI Flare returned " . count($cleanData) . " raw events\n";

        return $cleanData;
    }
}
