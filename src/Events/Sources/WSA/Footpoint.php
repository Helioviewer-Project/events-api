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
                        $seen          = [];

                        foreach (($window['forecast'] ?? []) as $contour) {
                            $lat = $contour['contour_coords']['lat'] ?? [];
                            $lon = $contour['contour_coords']['lon'] ?? [];
                            if (empty($lon) || empty($lat)) {
                                continue;
                            }
                            $probability = isset($contour['label']) ? (float) $contour['label'] : null;
                            $record = [
                                'product'        => 'footpoint',
                                'sat'            => $sat,
                                'input_map'      => $inputMap,
                                'adv'            => $adv,
                                'forecast_time'  => $forecastTime,
                                'forecast_range' => $forecastRange,
                                'probability'    => $probability,
                                'point_lat'      => $contour['label_coords']['lat'] ?? null,
                                'point_lon'      => $contour['label_coords']['lon'] ?? null,
                                'lat'            => $lat,
                                'lon'            => $lon,
                            ];
                            // Two contours of one forecast with the same probability: number the later ones
                            $key = self::probabilityKey($probability);
                            if (isset($seen[$key])) {
                                $record['tie'] = $seen[$key];
                            }
                            $seen[$key] = ($seen[$key] ?? 0) + 1;
                            $records[] = $record;
                        }
                    }

                    usleep($this->sleepMicros);
                }
            }
        }

        return $records;
    }

    /**
     * One contour of one forecast, told apart by its probability. CCMC re-sends
     * published forecasts with digits changing past about the 11th significant
     * one, so a geometry hash (the old key) turned every re-send into a new
     * event; 8 significant digits keep real contours apart and fold the noise.
     */
    public function extractRawRecordId(array $rawRecord): string
    {
        $contour = isset($rawRecord['probability'])
            ? 'p' . self::probabilityKey((float) $rawRecord['probability']) . (isset($rawRecord['tie']) ? ":{$rawRecord['tie']}" : '')
            : substr(sha1(json_encode($rawRecord['lon'] ?? []) . json_encode($rawRecord['lat'] ?? [])), 0, 12);

        return "{$rawRecord['sat']}:{$rawRecord['input_map']}:{$rawRecord['adv']}:{$rawRecord['forecast_time']}:{$contour}";
    }

    /**
     * A probability to 8 significant digits, e.g. 9.3639687e-01.
     */
    private static function probabilityKey(?float $probability): string
    {
        return $probability === null ? 'none' : sprintf('%.7e', $probability);
    }
}
