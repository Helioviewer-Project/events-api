<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Sources\WSA;

use Helioviewer\EventsApi\Utils\TimeRange;

/**
 * WSA magnetic-connectivity footpoints (Helio-Carrington).
 *
 * Despite the "footpoint" name the response is NOT single points: each forecast
 * item is a *probability contour* — `{contour_coords:{lat[],lon[]}, label, label_coords}`
 * where `label` is the connectivity probability (0..1, as a string) and `label_coords`
 * is the probability-peak point (the actual connectivity locus). We keep the polygon
 * (as `lat[]`/`lon[]`, same shape as CoronalHole) plus the probability and peak point.
 *
 * Discovers input_maps, locations (sats) and advanced_days from the capabilities
 * endpoint, then loops input_map × sat × adv.
 *
 * @package Helioviewer\EventsApi\Events\Sources\WSA
 */
class Footpoint extends Source
{
    public function getName(): string
    {
        return 'WSA_FOOTPOINTS';
    }

    public function fetchRawData(TimeRange $range): array
    {
        $dates = $this->dateParams($range);

        $caps      = $this->capabilities(self::API_BASE . '/load_helioviewer_footpoint_capabilities');
        $inputMaps = $caps['input_maps']    ?? [];
        $sats      = $caps['locations']     ?? [];
        $advDays   = $caps['advanced_days'] ?? [];

        $records = [];

        foreach ($inputMaps as $inputMap) {
            foreach ($sats as $sat) {
                foreach ($advDays as $adv) {
                    $url = self::API_BASE . '/load_helioviewer_footpoints?' . http_build_query(
                        $dates + ['input_map' => $inputMap, 'sat' => $sat, 'adv' => $adv]
                    );

                    $windows = $this->makeJsonRequest($url);

                    foreach ($windows as $window) {
                        if (!is_array($window)) {
                            continue;
                        }
                        $forecastTime  = $window['forecast_time']  ?? null;
                        $forecastRange = $window['forecast_range'] ?? null;

                        foreach (($window['forecast'] ?? []) as $contour) {
                            $lat = $contour['contour_coords']['lat'] ?? [];
                            $lon = $contour['contour_coords']['lon'] ?? [];
                            if (empty($lon) || empty($lat)) {
                                continue;
                            }
                            $records[] = [
                                'product'        => 'footpoint',
                                'sat'            => $sat,
                                'input_map'      => $inputMap,
                                'adv'            => $adv,
                                'forecast_time'  => $forecastTime,
                                'forecast_range' => $forecastRange,
                                'probability'    => isset($contour['label']) ? (float) $contour['label'] : null,
                                'point_lat'      => $contour['label_coords']['lat'] ?? null,
                                'point_lon'      => $contour['label_coords']['lon'] ?? null,
                                'lat'            => $lat,
                                'lon'            => $lon,
                            ];
                        }
                    }

                    usleep($this->sleepMicros);
                }
            }
        }

        return $records;
    }

    public function extractRawRecordId(array $rawRecord): string
    {
        // Geometry-based (mirrors CoronalHole): order-independent, and it distinguishes
        // nested probability contours that may share a peak point. See docs/WSA_PLAN.md.
        $geom = substr(sha1(json_encode($rawRecord['lon'] ?? []) . json_encode($rawRecord['lat'] ?? [])), 0, 12);

        return "{$rawRecord['sat']}:{$rawRecord['input_map']}:{$rawRecord['adv']}:{$rawRecord['forecast_time']}:{$geom}";
    }
}
