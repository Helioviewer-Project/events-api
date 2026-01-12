<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Sources\HEK;

use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Exception\InvalidEventException;
use Helioviewer\EventsApi\Utils\TimeRange;

/**
 * Data source for HEK (Heliophysics Event Knowledgebase) events.
 *
 * HEK is a comprehensive database of solar events from multiple observatories
 * and instruments. It provides standardized access to various event types
 * including flares, CMEs, active regions, coronal holes, and more.
 *
 * API Endpoint: https://www.lmsal.com/hek/her
 * Data Format: JSON with result array and overmax pagination flag
 *
 * @package    Helioviewer\EventsApi\Events\Sources\HEK
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 * @link       https://www.lmsal.com/heksearch HEK Search Interface
 */
class Source extends JsonSource
{
    private const BASE_URL = 'https://www.lmsal.com/hek/her';
    private const RESULT_LIMIT = 1000;

    /**
     * Get the unique identifier for this HEK data source.
     *
     * @return string The unique source identifier 'HEK'
     */
    public function getName(): string
    {
        return 'HEK';
    }

    /**
     * Fetch raw data from HEK for the specified time range.
     * Overrides parent to handle pagination using the overmax field.
     *
     * @param TimeRange $range The time range for which to fetch data
     *
     * @return array An array of raw event records
     */
    public function fetchRawData(TimeRange $range): array
    {
        $allEvents = [];
        $page = 1;

        do {
            $url = $this->buildUrlWithPage($range, $page);
            $jsonData = $this->makeJsonRequest($url);

            if (empty($jsonData)) {
                break;
            }

            $events = $this->extractDataFromResponse($jsonData);
            $allEvents = array_merge($allEvents, $events);

            // Check if more pages exist
            $overmax = $jsonData['overmax'] ?? 'false';
            $hasMorePages = ($overmax === 'true' || $overmax === true);

            $page++;

        } while ($hasMorePages);

        return $allEvents;
    }

    /**
     * Extract the unique remote ID from a HEK raw record.
     * Validates the record has required fields before processing.
     *
     * @param array $rawRecord The raw event record from HEK API
     *
     * @return string The unique identifier for this event
     *
     * @throws InvalidEventException If record is missing required fields or has unsupported event_type
     */
    public function extractRawRecordId(array $rawRecord): string
    {
        // Check kb_archivid exists
        if (empty($rawRecord['kb_archivid'])) {
            throw new InvalidEventException('HEK event missing kb_archivid');
        }

        // Check event_type exists
        if (empty($rawRecord['event_type'])) {
            throw new InvalidEventException('HEK event missing event_type: ' . $rawRecord['kb_archivid']);
        }

        return (string) $rawRecord['kb_archivid'];
    }

    /**
     * Build the HEK API URL (required by parent, uses page 1).
     *
     * @param TimeRange $range The time range for the query
     *
     * @return string Complete HEK API URL
     */
    protected function buildUrl(TimeRange $range): string
    {
        return $this->buildUrlWithPage($range, 1);
    }

    /**
     * Build the HEK API URL with time range and pagination parameters.
     *
     * @param TimeRange $range The time range for the query
     * @param int $page Page number (starts at 1)
     *
     * @return string Complete HEK API URL
     */
    protected function buildUrlWithPage(TimeRange $range, int $page): string
    {
        // Format times as YYYY-MM-DDTHH:MM:SS.000Z
        $startTime = $range->getStartDate('Y-m-d\TH:i:s.000\Z');
        $endTime = $range->getEndDate('Y-m-d\TH:i:s.000\Z');

        $params = [
            // Default parameters (always included)
            'cosec' => 2,
            'cmd' => 'search',
            'type' => 'column',
            'event_coordsys' => 'helioprojective',
            'x1' => -30000,
            'x2' => 30000,
            'y1' => -30000,
            'y2' => 30000,
            'requestfrom' => 'Helioviewer',
            'requestinghost' => parse_url($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', PHP_URL_HOST) ?: 'events.helioviewer.org',
            // Request parameters
            'event_starttime' => $startTime,
            'event_endtime' => $endTime,
            'event_type' => '**',  // All event types
            'showtests' => 'hide',
            'result_limit' => self::RESULT_LIMIT,
            'page' => $page,
        ];

        return self::BASE_URL . '?' . http_build_query($params);
    }

    /**
     * Extract event data from the HEK API JSON response.
     *
     * @param array $jsonData The decoded JSON response from HEK API
     *
     * @return array Array of event records
     */
    protected function extractDataFromResponse(array $jsonData): array
    {
        $results = $jsonData['result'] ?? [];

        // Sort each event's fields by key
        foreach ($results as &$event) {
            ksort($event);
        }

        return $results;
    }
}
