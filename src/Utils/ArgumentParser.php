<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Utils;

use Carbon\Carbon;
use Exception;

/**
 * Command Line Argument Parser
 *
 * Utility class for parsing and validating command line arguments,
 * specifically for date range parsing in event collection scripts.
 * Supports flexible date formats including ISO 8601 datetime strings.
 *
 * @package Helioviewer\EventsApi\Utils
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class ArgumentParser
{
    /**
     * Parse command line arguments to extract date range.
     *
     * Supports multiple argument patterns:
     * - No args: Current day (00:00:00 to 23:59:59)
     * - One arg: Single day or specific datetime
     * - Two args: Start and end date/datetime range
     *
     * Accepted date formats:
     * - Y-m-d (e.g., "2024-01-15")
     * - Y-m-d\TH:i:s (e.g., "2024-01-15T14:30:00")
     *
     * @param array<string> $argv Command line arguments array
     *
     * @return array{int, int} Array containing [start_timestamp, end_timestamp]
     *
     * @throws Exception If date format is invalid or start date is after end date
     */
    public static function parseDateRange(array $argv): array
    {
        $argc = count($argv);
        
        if ($argc == 1) {
            // No parameters provided: use current date from 00:00:00 to 23:59:59
            $start = Carbon::today()->timestamp;
            $end = Carbon::today()->endOfDay()->timestamp;
        } elseif ($argc == 2) {
            // Single parameter: process as either full day or specific datetime
            try {
                // Check if parameter contains time component (ISO format with 'T')
                if (strpos($argv[1], 'T') !== false) {
                    // Parse ISO datetime format (e.g., 2024-01-15T14:30:00)
                    $date = Carbon::createFromFormat('Y-m-d\TH:i:s', $argv[1]);
                    $start = $date->timestamp;
                    // For specific datetime, extend to end of that day
                    $end = $date->endOfDay()->timestamp;
                } else {
                    // Parse date-only format (e.g., 2024-01-15)
                    $date = Carbon::createFromFormat('Y-m-d', $argv[1]);
                    // Cover the entire day from start to end
                    $start = $date->startOfDay()->timestamp;
                    $end = $date->endOfDay()->timestamp;
                }
            } catch (Exception $e) {
                throw new Exception("Invalid date format. Use Y-m-d or Y-m-d\TH:i:s format (e.g., 2024-01-15 or 2024-01-15T14:30:00)");
            }
        } else {
            // Two parameters: explicit start and end date/datetime range
            try {
                // Parse start date with format detection
                if (strpos($argv[1], 'T') !== false) {
                    $startDate = Carbon::createFromFormat('Y-m-d\TH:i:s', $argv[1]);
                } else {
                    $startDate = Carbon::createFromFormat('Y-m-d', $argv[1]);
                }
                
                // Parse end date with format detection
                if (strpos($argv[2], 'T') !== false) {
                    $endDate = Carbon::createFromFormat('Y-m-d\TH:i:s', $argv[2]);
                } else {
                    $endDate = Carbon::createFromFormat('Y-m-d', $argv[2]);
                }
                
                // Validate that start date is not after end date
                if ($startDate->gt($endDate)) {
                    throw new Exception("Start date must be before or equal to end date");
                }
                
                $start = $startDate->timestamp;
                $end = $endDate->timestamp;
            } catch (Exception $e) {
                throw new Exception("Invalid date format. Use Y-m-d or Y-m-d\TH:i:s format (e.g., 2024-01-15 or 2024-01-15T14:30:00)");
            }
        }
        
        return [$start, $end];
    }
}