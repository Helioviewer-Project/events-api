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
}