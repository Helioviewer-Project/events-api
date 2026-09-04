<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\WSA;

use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Events\Sources\SourceInterface;

/**
 * WSA Coronal-Hole boundary processor.
 *
 * Each raw record is one forecast WINDOW of Helio-Carrington boundaries
 * (`{product:'coronal_hole', sat, input_map, real, forecast_time, forecast_range,
 * contours:[{lat[],lon[]},…]}`). All contours become the event footprint — a LIST of
 * polygons `[[{x,y},…],…]` — and the pin is the largest contour's centroid. Coordinates
 * stay in Carrington degrees and are rotated to HPC at query time by CoordinateRotator
 * (coordinate_system='carrington'). Path is the input map alone — no satellite
 * level (see CoronalHole::SAT) and no realization level; the 12 AGONG members
 * sit under the one node and are told apart by their label. See docs/WSA_PLAN.md.
 *
 * @package Helioviewer\EventsApi\Events\Processors\WSA
 */
class CoronalHoleProcessor extends Processor
{
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        return ($rawRecord['product'] ?? null) === 'coronal_hole';
    }

    public function process(array $rawRecord, SourceInterface $source): Event
    {
        $inputMap = (string) ($rawRecord['input_map'] ?? '');
        $real     = $rawRecord['real'] ?? 0;

        // All boundary contours of the window → footprint as a LIST of polygons,
        // each polygon a list of {x:lon, y:lat} points (Carrington degrees).
        $footprint   = [];
        $totalPoints = 0;
        $largest     = null;
        $largestArea = -1.0;
        foreach (($rawRecord['contours'] ?? []) as $contour) {
            $lon = $contour['lon'] ?? [];
            $lat = $contour['lat'] ?? [];
            $n   = min(count($lon), count($lat));
            if ($n === 0) {
                continue;
            }
            $polygon = [];
            for ($i = 0; $i < $n; $i++) {
                $polygon[] = ['x' => (float) $lon[$i], 'y' => (float) $lat[$i]];
            }
            $footprint[] = $polygon;
            $totalPoints += $n;
            $area = $this->polygonArea($polygon);
            if ($area > $largestArea) {
                $largestArea = $area;
                $largest     = $polygon;
            }
        }

        // Pin = a point INSIDE the largest contour (by area), so the marker lands on
        // the biggest hole — its centroid when that falls inside, else the midpoint of
        // the widest span through it (concave contours can put the centroid outside).
        // Approximate for contours wrapping the 0/360 seam — see plan.
        [$cx, $cy] = $largest !== null ? $this->pointInsidePolygon($largest) : [0.0, 0.0];

        [$start, $peak, $end] = $this->timeline($rawRecord);

        // Path: input map only. No satellite level — the maps are identical
        // whatever `sat` is requested — and no realization level either: all 12
        // AGONG members list under the one node, told apart by their label.
        $leaf = $inputMap;

        // Labels — the realization is not a path level, so it has to be legible
        // here (GONGZ has a single member and carries none):
        //   label:       "Real {n}, Forecast: {t}"
        //   short_label: "R{n}, Forecast: {t}"
        $forecastTag = $this->forecastTag($peak);
        $shortLabel  = $inputMap === 'AGONG' ? "R{$real}, {$forecastTag}" : $forecastTag;
        $label       = $inputMap === 'AGONG' ? "Real {$real}, {$forecastTag}" : $forecastTag;

        $event = new Event();
        $event->fill([
            'source_id'         => JsonSource::WSA,
            'path'              => $leaf,
            'start'             => $start,
            'peak'              => $peak,
            'end'               => $end,
            'coordinate_time'   => $peak,
            'hv_hpc_x'          => $cx,
            'hv_hpc_y'          => $cy,
            'coordinate_system' => 'carrington',
            'footprint'         => $footprint,
            'label'             => $label,
            'short_label'       => $shortLabel,
            'legacy_version'    => null,
            'legacy_type'       => 'CH',
            'legacy_pin'        => 'CH',
        ]);

        $event->legacy_views = [[
            'name'    => 'WSA Coronal Hole',
            'content' => [
                'Product'           => 'Coronal Hole boundaries',
                'Input map'         => $inputMap,
                'Realization'       => $inputMap === 'AGONG' ? $real : 'n/a',
                'Forecast time'     => $rawRecord['forecast_time'] ?? null,
                'Forecast window'   => $this->forecastWindow($rawRecord),
                'Contours'          => count($footprint),
                'Boundary points'   => $totalPoints,
                'Coordinate system' => 'Helio-Carrington',
                'WSA Dashboard'     => self::DASHBOARD_URL,
                'Community Tools'   => self::DASHBOARD_COMMUNITY_URL,
            ],
        ]];
        $event->legacy_link = $this->dashboardLink();

        return $event;
    }

    /**
     * Unsigned shoelace area of a polygon (lon/lat degrees) — a relative size
     * measure for picking the biggest contour. Seam-wrapping contours (0/360)
     * are the known approximation, see docs/WSA_PLAN.md.
     *
     * @param array<int,array{x:float,y:float}> $polygon
     */
    private function polygonArea(array $polygon): float
    {
        $n   = count($polygon);
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $j    = ($i + 1) % $n;
            $sum += $polygon[$i]['x'] * $polygon[$j]['y'] - $polygon[$j]['x'] * $polygon[$i]['y'];
        }
        return abs($sum) / 2.0;
    }

    /**
     * A representative point for the contour, guaranteed to be a valid
     * Carrington coordinate (lat within ±90, lon within 0..360).
     *
     * The area-centroid maths below assumes a simple closed polygon on a flat
     * plane. A polar coronal hole wraps every longitude (lon 0..359 around the
     * pole), which on a lon/lat plane is a band across the whole map rather
     * than a closed blob — the formula then returns coordinates far outside the
     * contour's own data (e.g. latitude -124 for vertices spanning -89..-6).
     * Such a pin is unusable: HPCResolver rejects out-of-range lat/lon, the
     * event never gets its HPC snapshot, and the API falls back to serving raw
     * degrees. So anything out of range falls back to the vertex average, which
     * cannot leave the range.
     *
     * @param array<int,array{x:float,y:float}> $polygon
     * @return array{0:float,1:float} [x, y]
     */
    private function pointInsidePolygon(array $polygon): array
    {
        $average = $this->vertexAverage($polygon);
        [$x, $y]  = $this->areaBasedPoint($polygon, $average);

        if ($y < -90.0 || $y > 90.0 || $x < 0.0 || $x > 360.0) {
            return $average;
        }

        return [$x, $y];
    }

    /**
     * Arithmetic mean of the vertices — always inside the coordinate range.
     *
     * @param array<int,array{x:float,y:float}> $polygon
     * @return array{0:float,1:float} [x, y]
     */
    private function vertexAverage(array $polygon): array
    {
        $n  = count($polygon);
        $sx = 0.0;
        $sy = 0.0;
        foreach ($polygon as $point) {
            $sx += $point['x'];
            $sy += $point['y'];
        }
        return $n > 0 ? [$sx / $n, $sy / $n] : [0.0, 0.0];
    }

    /**
     * Area centroid when it falls inside the polygon; otherwise the midpoint of
     * the widest inside-interval of the horizontal scanline through its y
     * (a concave contour can put its centroid outside itself). May return
     * out-of-range values for contours that wrap the sphere — the caller checks.
     *
     * @param array<int,array{x:float,y:float}> $polygon
     * @param array{0:float,1:float} $average Fallback for degenerate rings
     * @return array{0:float,1:float} [x, y]
     */
    private function areaBasedPoint(array $polygon, array $average): array
    {
        $n = count($polygon);

        $area = 0.0;
        $cx   = 0.0;
        $cy   = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $j     = ($i + 1) % $n;
            $cross = $polygon[$i]['x'] * $polygon[$j]['y'] - $polygon[$j]['x'] * $polygon[$i]['y'];
            $area += $cross;
            $cx   += ($polygon[$i]['x'] + $polygon[$j]['x']) * $cross;
            $cy   += ($polygon[$i]['y'] + $polygon[$j]['y']) * $cross;
        }
        if (abs($area) < 1e-9) {
            return $average;   // degenerate (zero-area) ring
        }
        $cx /= 3 * $area;
        $cy /= 3 * $area;

        // Scanline through the centroid's y: even-odd crossing pairs bound the
        // inside-intervals. Centroid inside one of them → keep it; otherwise use
        // the midpoint of the widest interval.
        $crossings = [];
        for ($i = 0; $i < $n; $i++) {
            $j  = ($i + 1) % $n;
            $y1 = $polygon[$i]['y'];
            $y2 = $polygon[$j]['y'];
            if (($y1 <= $cy) === ($y2 <= $cy)) {
                continue; // edge does not cross the scanline
            }
            $t = ($cy - $y1) / ($y2 - $y1);
            $crossings[] = $polygon[$i]['x'] + $t * ($polygon[$j]['x'] - $polygon[$i]['x']);
        }
        sort($crossings);

        $bestMid   = null;
        $bestWidth = -1.0;
        for ($k = 0; $k + 1 < count($crossings); $k += 2) {
            $x1 = $crossings[$k];
            $x2 = $crossings[$k + 1];
            if ($cx >= $x1 && $cx <= $x2) {
                return [$cx, $cy]; // centroid already inside
            }
            if ($x2 - $x1 > $bestWidth) {
                $bestWidth = $x2 - $x1;
                $bestMid   = ($x1 + $x2) / 2.0;
            }
        }

        return $bestMid !== null ? [$bestMid, $cy] : [$cx, $cy];
    }
}
