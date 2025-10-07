<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Utils;

use DateTime;
use InvalidArgumentException;

/**
 * Timestamp Parser Utility
 * 
 * Handles parsing of various timestamp formats into Unix timestamps
 * Supports Unix timestamps, ISO 8601, and various date string formats
 * 
 * @package    Helioviewer\EventsApi\Utils
 * @since      1.0.0
 */
class TimestampParser
{
    /**
     * Supported date formats for parsing
     */
    private const DATE_FORMATS = [
        'Y-m-d\TH:i:s.uP',     // ISO 8601 with microseconds and timezone
        'Y-m-d\TH:i:s.u\Z',    // ISO 8601 with milliseconds and Z
        'Y-m-d\TH:i:sP',       // ISO 8601 with timezone
        'Y-m-d\TH:i:s\Z',      // ISO 8601 with Z
        'Y-m-d\TH:i:s',        // ISO 8601 without timezone
        'Y-m-d H:i:s',         // Space-separated
    ];
    
    /**
     * Parse a timestamp string or number into Unix timestamp
     * 
     * @param string|int $timestamp The timestamp to parse (Unix timestamp or date string)
     * @return int Unix timestamp
     * @throws InvalidArgumentException If timestamp format is invalid
     */
    public static function parseTimestamp($timestamp): int
    {
        return self::parse($timestamp);
    }

    public static function parse($timestamp): int
    {
        // Handle URL encoding first
        if (is_string($timestamp)) {
            $timestamp = urldecode($timestamp);
        }
        
        // If it's already numeric, treat as Unix timestamp
        if (is_numeric($timestamp)) {
            return (int) $timestamp;
        }
        
        // Try each date format
        foreach (self::DATE_FORMATS as $format) {
            $dateTime = DateTime::createFromFormat($format, $timestamp);
            if ($dateTime !== false) {
                return $dateTime->getTimestamp();
            }
        }
        
        // If no format matched, try PHP's strtotime as fallback
        $time = strtotime($timestamp);
        if ($time !== false) {
            return $time;
        }
        
        throw new InvalidArgumentException('Invalid timestamp format: ' . $timestamp);
    }
    
    /**
     * Parse timestamp and return as ISO 8601 string with Z timezone
     * 
     * @param string|int $timestamp The timestamp to parse
     * @return string ISO 8601 formatted date string (Y-m-d\TH:i:s\Z)
     * @throws InvalidArgumentException If timestamp format is invalid
     */
    public function toIso8601($timestamp): string
    {
        $unixTimestamp = $this->parse($timestamp);
        return date('Y-m-d\TH:i:s\Z', $unixTimestamp);
    }
    
    /**
     * Parse timestamp and return as human-readable date string
     * 
     * @param string|int $timestamp The timestamp to parse
     * @param string $format Output format (default: Y-m-d H:i:s)
     * @return string Formatted date string
     * @throws InvalidArgumentException If timestamp format is invalid
     */
    public function toDateString($timestamp, string $format = 'Y-m-d H:i:s'): string
    {
        $unixTimestamp = $this->parse($timestamp);
        return date($format, $unixTimestamp);
    }
    
    /**
     * Validate if a timestamp string is parseable
     * 
     * @param string|int $timestamp The timestamp to validate
     * @return bool True if valid, false otherwise
     */
    public function isValid($timestamp): bool
    {
        try {
            $this->parse($timestamp);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }
    
    /**
     * Get the list of supported date formats
     * 
     * @return array
     */
    public function getSupportedFormats(): array
    {
        return self::DATE_FORMATS;
    }
}