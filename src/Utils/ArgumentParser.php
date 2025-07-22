<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Utils;

use Carbon\Carbon;
use Exception;

class ArgumentParser
{
    public static function parseDateRange(array $argv): array
    {
        $argc = count($argv);
        
        if ($argc == 1) {
            // No parameters: use current date only
            $start = Carbon::today()->timestamp;
            $end = Carbon::today()->endOfDay()->timestamp;
        } elseif ($argc == 2) {
            // One parameter: use start-date only (that single day or specific datetime)
            try {
                // Try ISO format first (e.g., 2024-01-15T14:30:00)
                if (strpos($argv[1], 'T') !== false) {
                    $date = Carbon::createFromFormat('Y-m-d\TH:i:s', $argv[1]);
                    $start = $date->timestamp;
                    $end = $date->endOfDay()->timestamp;
                } else {
                    // Fall back to date-only format (e.g., 2024-01-15)
                    $date = Carbon::createFromFormat('Y-m-d', $argv[1]);
                    $start = $date->startOfDay()->timestamp;
                    $end = $date->endOfDay()->timestamp;
                }
            } catch (Exception $e) {
                throw new Exception("Invalid date format. Use Y-m-d or Y-m-d\TH:i:s format (e.g., 2024-01-15 or 2024-01-15T14:30:00)");
            }
        } else {
            // Two parameters: start-date and end-date range
            try {
                // Try ISO format first for both parameters
                if (strpos($argv[1], 'T') !== false) {
                    $startDate = Carbon::createFromFormat('Y-m-d\TH:i:s', $argv[1]);
                } else {
                    $startDate = Carbon::createFromFormat('Y-m-d', $argv[1]);
                }
                
                if (strpos($argv[2], 'T') !== false) {
                    $endDate = Carbon::createFromFormat('Y-m-d\TH:i:s', $argv[2]);
                } else {
                    $endDate = Carbon::createFromFormat('Y-m-d', $argv[2]);
                }
                
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