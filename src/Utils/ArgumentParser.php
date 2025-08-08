<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Utils;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Command Line Argument Parser
 *
 * Simple utility for parsing date arguments in Y-m-d format.
 */
class ArgumentParser
{
    /**
     * Parse date arguments from command line.
     *
     * @param string|null $startDate First date argument (Y-m-d format)
     * @param string|null $endDate Second date argument (Y-m-d format)
     *
     * @return array{int, int} Array containing [start_timestamp, end_timestamp]
     *
     * @throws InvalidArgumentException If date format is invalid or start >= end date
     */
    public static function parseDateRange(?string $startDate, ?string $endDate = null): array
    {
        if (!$startDate) {
            $startDate = Carbon::today()->format('Y-m-d');
        }

        try {
            $start = Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
        } catch (Exception $e) {
            throw new InvalidArgumentException("Invalid start date format. Use Y-m-d format (e.g., 2024-01-15)");
        }

        if ($endDate) {
            try {
                $end = Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
            } catch (Exception $e) {
                throw new InvalidArgumentException("Invalid end date format. Use Y-m-d format (e.g., 2024-01-15)");
            }
        } else {
            $end = $start->copy()->endOfDay();
        }

        if ($start->gte($end)) {
            throw new InvalidArgumentException("Start date must be before end date");
        }

        return [$start->timestamp, $end->timestamp];
    }
}