<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard;

use Helioviewer\EventsApi\Events\Sources\SourceInterface;

/**
 * Models that name regions by their own numbers: ASSA, ASSA 24h, ASAP and
 * (for regions NOAA has not numbered) Met Office.
 *
 * The row's own lat/lon is used to find the NOAA region it means: the nearest
 * one that day, rotated to the row's time, within 10°. That region's position
 * is stored; with none that close, CCMC's own position is kept, flagged.
 *
 * @package    Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class ModelRegionProcessor extends Processor
{
    private const SOURCES = [
        'FLARE_SCOREBOARD_ASSA_1_REGIONS',
        'FLARE_SCOREBOARD_ASSA_24H_1_REGIONS',
        'FLARE_SCOREBOARD_ASAP_1_REGIONS',
        'FLARE_SCOREBOARD_MetOffice - Solar Region Summary_REGIONS',
    ];

    private const MATCH_DEGREES = 10.0;

    /**
     * Handles ASSA, ASSA 24h, ASAP and Met Office rows.
     *
     * @param SourceInterface $source    Data source
     * @param array           $rawRecord Raw record
     * @return bool
     */
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        return in_array($source->getName(), self::SOURCES, true) && isset($rawRecord['start_window']);
    }

    /**
     * The row's NOAA region; else the NOAA region nearest to the row's position; else CCMC's position.
     *
     * @param array $rawRecord Raw record
     * @return array|null Coordinate data, or null when the row has no position
     */
    protected function readCoordinates(array $rawRecord): ?array
    {
        // Met Office rows can name a NOAA region; ASSA and ASAP never do
        if (isset($rawRecord['NOAARegionId']) && (int) $rawRecord['NOAARegionId'] > 0) {
            $coordinates = $this->readCoordinatesFromNOAARegion((int) $rawRecord['NOAARegionId'], $rawRecord['start_window']);
            if ($coordinates !== null) {
                return $coordinates;
            }
        }

        if (isset($rawRecord['ModelLatitude'], $rawRecord['ModelLongitude'])) {
            $at = strtotime($rawRecord['ModelLocationTime'] ?? $rawRecord['start_window']);
            [$noaaId, $distance] = $this->nearestNoaa((float) $rawRecord['ModelLatitude'], (float) $rawRecord['ModelLongitude'], $at);
            $where = sprintf('Model %s (%s, %s) at %s', $rawRecord['ModelRegionId'] ?? '?', $rawRecord['ModelLatitude'], $rawRecord['ModelLongitude'], gmdate('Y-m-d H:i', $at));
            if ($noaaId !== null && $distance <= self::MATCH_DEGREES) {
                $this->logger->info(sprintf('Position | MATCH | %s → NOAA %d, %.1f° away', $where, $noaaId, $distance));
                $coordinates = $this->readCoordinatesFromNOAARegion($noaaId, $rawRecord['start_window']);
                if ($coordinates !== null) {
                    return $coordinates;
                }
            } else {
                $this->logger->info("Position | MATCH | {$where} → no NOAA region within " . self::MATCH_DEGREES . '°'
                    . ($noaaId === null ? ' (no NOAA regions that day)' : sprintf(' (nearest NOAA %d, %.1f°)', $noaaId, $distance)));
            }
        }

        return $this->readCoordinatesFromRow($rawRecord);
    }

    /**
     * The NOAA region nearest to (lat, lon) at $at: each region's latest daily
     * position rotated to $at. The caller decides whether it is close enough.
     *
     * @param float $lat Row latitude
     * @param float $lon Row longitude (Stonyhurst)
     * @param int   $at  Row location time (Unix timestamp)
     * @return array{0: int|null, 1: float} [NOAA region number or null, distance in degrees]
     */
    private function nearestNoaa(float $lat, float $lon, int $at): array
    {
        $best = null;
        $bestDistance = INF;
        foreach ($this->positions?->noaaRegionsBefore($at) ?? [] as $noaaId => $region) {
            $hours = ($at - $region['time']) / 3600;
            $distance = self::angularDistance($lat, $lon, $region['latitude'], self::rotate($region['latitude'], $region['longitude'], $hours));
            if ($distance < $bestDistance) {
                $best = $noaaId;
                $bestDistance = $distance;
            }
        }

        return [$best, $bestDistance];
    }

    /**
     * A Stonyhurst longitude moved forward by differential rotation.
     *
     * @param float $lat   Latitude
     * @param float $lon   Longitude at the start
     * @param float $hours Hours to rotate (negative rotates back)
     * @return float Longitude after $hours
     */
    private static function rotate(float $lat, float $lon, float $hours): float
    {
        // Sidereal rate 14.38 − 2.77·sin²(lat) °/day, less Earth's orbital motion (~0.99 °/day)
        return $lon + (14.38 - 2.77 * sin(deg2rad($lat)) ** 2 - 0.99) * $hours / 24;
    }

    /**
     * Great-circle distance between two heliographic positions, in degrees.
     *
     * @return float
     */
    private static function angularDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $cos = sin(deg2rad($lat1)) * sin(deg2rad($lat2))
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lon2 - $lon1));

        return rad2deg(acos(max(-1.0, min(1.0, $cos))));
    }
}
