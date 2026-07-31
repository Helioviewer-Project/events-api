<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\WSA;

use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Events\Sources\SourceInterface;

/**
 * WSA Magnetic-Connectivity footpoint processor.
 *
 * Despite the "footpoint" name each raw record is a probability *contour*
 * (`{product:'footpoint', sat, input_map, adv, forecast_time, forecast_range,
 * probability, point_lat, point_lon, lat[], lon[]}`): the polygon becomes the event
 * footprint, and the pin is `label_coords` (point_lon/point_lat) — the connectivity
 * peak, not the polygon centroid. `probability` (0..1) is surfaced in the label/view.
 * Coordinates stay in Carrington degrees and are rotated to HPC at query time by
 * CoordinateRotator (coordinate_system='carrington'). See docs/WSA_PLAN.md.
 *
 * @package Helioviewer\EventsApi\Events\Processors\WSA
 */
class FootpointProcessor extends Processor
{
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        return ($rawRecord['product'] ?? null) === 'footpoint';
    }

    public function process(array $rawRecord, SourceInterface $source): Event
    {
        $sat      = (string) ($rawRecord['sat'] ?? '');
        $inputMap = (string) ($rawRecord['input_map'] ?? '');
        $adv      = $rawRecord['adv'] ?? null;
        $prob     = $rawRecord['probability'] ?? null;

        // Probability contour → one polygon of {x:lon, y:lat} (Carrington degrees).
        $lon = $rawRecord['lon'] ?? [];
        $lat = $rawRecord['lat'] ?? [];
        $n   = min(count($lon), count($lat));
        $polygon = [];
        $sx = 0.0;
        $sy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $lon[$i];
            $y = (float) $lat[$i];
            $polygon[] = ['x' => $x, 'y' => $y];
            $sx += $x;
            $sy += $y;
        }
        // Canonical footprint shape: a LIST of polygons — a footpoint has one.
        $footprint = empty($polygon) ? [] : [$polygon];

        // Pin = label_coords (the connectivity peak). Fall back to centroid if absent.
        if (isset($rawRecord['point_lon'], $rawRecord['point_lat'])) {
            $cx = (float) $rawRecord['point_lon'];
            $cy = (float) $rawRecord['point_lat'];
        } else {
            $cx = $n > 0 ? $sx / $n : 0.0;
            $cy = $n > 0 ? $sy / $n : 0.0;
        }

        [$start, $peak, $end] = $this->timeline($rawRecord);

        // Footpoints only carry the AGONG input map (no realization level).
        $leaf = "{$sat}>>{$inputMap}";

        // Labels — compact: "P:" = connectivity probability as a percent (1 decimal;
        // the exact value stays in the detail view), satellite only in the full label.
        //   label:       "{sat}, P: {p}%, Forecast: {t}"
        //   short_label: "P: {p}%, Forecast: {t}"
        $probTag    = $prob !== null ? 'P: ' . round((float) $prob * 100, 1) . '%, ' : '';
        $shortLabel = $probTag . $this->forecastTag($peak);
        $label      = "{$sat}, {$shortLabel}";

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
            'legacy_type'       => 'MC',
            'legacy_pin'        => 'MC',
        ]);

        $event->legacy_views = [[
            'name'    => 'WSA Magnetic Connectivity',
            'content' => [
                'Product'                => 'Magnetic connectivity footpoint',
                'Target (sat)'           => $sat,
                'Input map'              => $inputMap,
                'Advanced days'          => $adv,
                'Connectivity probability' => $prob !== null ? round((float) $prob * 100, 2) . '%' : null,
                'Forecast time'          => $rawRecord['forecast_time'] ?? null,
                'Forecast window'        => $this->forecastWindow($rawRecord),
                'Contour points'         => $n,
                'Coordinate system'      => 'Helio-Carrington',
                'WSA Dashboard'          => self::DASHBOARD_URL,
                'Community Tools'        => self::DASHBOARD_COMMUNITY_URL,
            ],
        ]];
        $event->legacy_link = $this->dashboardLink();

        return $event;
    }
}
