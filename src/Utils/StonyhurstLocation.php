<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Utils;

/**
 * Strict reading of Stonyhurst positions as sources give them. Anything
 * outside latitude −90…90 or longitude −180…180 is no position: neither the
 * coordinator (it wraps any longitude) nor SunPy would reject it, and DONKI
 * uses 999 for "unknown".
 *
 * @package Helioviewer\EventsApi\Utils
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class StonyhurstLocation
{
    /**
     * A location such as "N25W16", "S8W110" or "N10E110": 1–2 latitude digits,
     * 1–3 longitude digits. North and west are positive.
     *
     * @return array{0: float, 1: float}|null [latitude, longitude], or null when unreadable or out of range
     */
    public static function parse(?string $text): ?array
    {
        if ($text === null || !preg_match('/^([NS])(\d{1,2})([EW])(\d{1,3})$/', strtoupper(trim($text)), $m)) {
            return null;
        }

        return self::fromLatLon(
            $m[1] === 'N' ? (int) $m[2] : -(int) $m[2],
            $m[3] === 'W' ? (int) $m[4] : -(int) $m[4]
        );
    }

    /**
     * @return array{0: float, 1: float}|null [latitude, longitude], or null unless both are numbers in range
     */
    public static function fromLatLon(mixed $latitude, mixed $longitude): ?array
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }
        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        return [$latitude, $longitude];
    }
}
