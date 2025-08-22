<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Utils;

/**
 * Time Range Value Object
 *
 * Immutable value object representing a time range with start and end timestamps.
 * Provides utilities for date formatting, duration calculation, and containment checks.
 * Validates that start time is not after end time upon construction.
 *
 * @package Helioviewer\EventsApi\Utils
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class TimeRange
{
    /**
     * Construct a new TimeRange instance.
     *
     * @param int $start The start timestamp (Unix timestamp)
     * @param int $end The end timestamp (Unix timestamp)
     *
     * @throws \InvalidArgumentException If start time is after end time
     */
    public function __construct(
        public readonly int $start,
        public readonly int $end
    ) {
        if ($start > $end) {
            throw new \InvalidArgumentException('Start time cannot be after end time');
        }
    }
    
    /**
     * Create a TimeRange from date strings.
     *
     * @param string $startDate Start date string (any format parseable by strtotime)
     * @param string $endDate End date string (any format parseable by strtotime)
     *
     * @return self New TimeRange instance
     *
     * @throws \InvalidArgumentException If either date string is invalid
     */
    public static function fromDates(string $startDate, string $endDate): self
    {
        $start = strtotime($startDate);
        $end = strtotime($endDate);
        
        if ($start === false || $end === false) {
            throw new \InvalidArgumentException('Invalid date format');
        }
        
        return new self($start, $end);
    }
    
    /**
     * Create a TimeRange from Unix timestamps.
     *
     * @param int $start Start timestamp
     * @param int $end End timestamp
     *
     * @return self New TimeRange instance
     *
     * @throws \InvalidArgumentException If start is after end
     */
    public static function fromTimestamps(int $start, int $end): self
    {
        return new self($start, $end);
    }
    
    /**
     * Create a TimeRange from an event object or array.
     *
     * @param array<string, mixed>|object $event Event data with start and end properties
     *
     * @return self New TimeRange instance
     *
     * @throws \InvalidArgumentException If event doesn't have required start/end properties
     */
    public static function fromEvent($event): self
    {
        if (is_array($event)) {
            return new self($event['start'], $event['end']);
        }
        return new self($event->start, $event->end);
    }
    
    /**
     * Get the start timestamp formatted as a date string.
     *
     * @param string $format Date format string (default: 'Y-m-d')
     *
     * @return string Formatted start date
     */
    public function getStartDate(string $format = 'Y-m-d'): string
    {
        return date($format, $this->start);
    }
    
    /**
     * Get the end timestamp formatted as a date string.
     *
     * @param string $format Date format string (default: 'Y-m-d')
     *
     * @return string Formatted end date
     */
    public function getEndDate(string $format = 'Y-m-d'): string
    {
        return date($format, $this->end);
    }
    
    /**
     * Get the duration of the time range in seconds.
     *
     * @return int Duration in seconds
     */
    public function getDuration(): int
    {
        return $this->end - $this->start;
    }
    
    /**
     * Check if a timestamp falls within this time range.
     *
     * @param int $timestamp Unix timestamp to check
     *
     * @return bool True if timestamp is within range (inclusive)
     */
    public function contains(int $timestamp): bool
    {
        return $timestamp >= $this->start && $timestamp <= $this->end;
    }
    
    /**
     * Split the time range into daily chunks.
     *
     * @return array<TimeRange> Array of TimeRange objects, one for each day
     */
    public function splitByDays(): array
    {
        return $this->splitByInterval(1);
    }
    
    /**
     * Split the time range into chunks of specified interval in days.
     *
     * @param int $intervalDays Number of days per chunk (default: 1)
     *
     * @return array<TimeRange> Array of TimeRange objects, one for each interval
     */
    public function splitByInterval(int $intervalDays = 1): array
    {
        if ($intervalDays < 1) {
            throw new \InvalidArgumentException('Interval must be at least 1 day');
        }
        
        $chunks = [];
        
        // Start from the beginning of the start date
        $currentStart = strtotime(date('Y-m-d 00:00:00', $this->start));
        
        while ($currentStart < $this->end) {
            // Calculate end of current interval
            $intervalEnd = strtotime("+{$intervalDays} days", $currentStart) - 1; // -1 to end at 23:59:59
            
            // Don't go beyond the original end time
            $chunkEnd = min($intervalEnd, $this->end);
            
            // Only add chunk if there's actual time in it
            if ($currentStart <= $chunkEnd) {
                $chunks[] = new self(max($currentStart, $this->start), $chunkEnd);
            }
            
            // Move to next interval
            $currentStart = strtotime("+{$intervalDays} days", $currentStart);
        }
        
        return $chunks;
    }
}