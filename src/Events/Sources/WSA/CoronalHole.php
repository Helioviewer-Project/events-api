<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Sources\WSA;

use Helioviewer\EventsApi\Utils\TimeRange;

/**
 * WSA coronal-hole boundaries (Helio-Carrington).
 *
 * Discovers input_maps + locations (sats) from the capabilities endpoint, then
 * loops input_map × sat × realization (AGONG → 0..11, else → [0]). Each response
 * is a list of forecast windows; every WINDOW becomes one raw record carrying all
 * of its contour polygons (the processor turns them into a multi-polygon footprint).
 *
 * @package Helioviewer\EventsApi\Events\Sources\WSA
 */
class CoronalHole extends Source
{
    public function getName(): string
    {
        return 'WSA_CORONAL_HOLES';
    }

    public function fetchRawData(TimeRange $range): array
    {
        $dates = $this->dateParams($range);

        $caps      = $this->capabilities(self::API_BASE . '/load_helioviewer_coronal_hole_capabilities');
        $inputMaps = $caps['input_maps'] ?? [];
        $sats      = $caps['locations']  ?? [];

        $records = [];

        foreach ($inputMaps as $inputMap) {
            $reals = ($inputMap === 'AGONG') ? range(0, 11) : [0];

            foreach ($sats as $sat) {
                foreach ($reals as $real) {
                    $url = self::API_BASE . '/load_helioviewer_coronal_holes?' . http_build_query(
                        $dates + ['input_map' => $inputMap, 'sat' => $sat, 'real' => $real]
                    );

                    $windows = $this->makeJsonRequest($url); // list of forecast windows

                    foreach ($windows as $window) {
                        if (!is_array($window)) {
                            continue;
                        }

                        // Group ALL of the window's contours into one raw record →
                        // one Event with a multi-polygon footprint.
                        $contours = [];
                        foreach (($window['forecast'] ?? []) as $contour) {
                            $lat = $contour['coords']['lat'] ?? [];
                            $lon = $contour['coords']['lon'] ?? [];
                            if (empty($lon) || empty($lat)) {
                                continue;
                            }
                            $contours[] = ['lat' => $lat, 'lon' => $lon];
                        }
                        if (empty($contours)) {
                            continue;
                        }

                        $records[] = [
                            'product'        => 'coronal_hole',
                            'sat'            => $sat,
                            'input_map'      => $inputMap,
                            'real'           => $real,
                            'forecast_time'  => $window['forecast_time'] ?? null,
                            'forecast_range' => $window['forecast_range'] ?? null,
                            'contours'       => $contours,
                        ];
                    }

                    usleep($this->sleepMicros);
                }
            }
        }

        return $records;
    }

    public function extractRawRecordId(array $rawRecord): string
    {
        // One record per (sat, input_map, real, forecast window) now that contours are
        // grouped — the window's start/end times are the only discriminator needed, so
        // the id is fully readable (no geometry hash).
        $range = $rawRecord['forecast_range'] ?? [];
        $start = $range[0] ?? ($rawRecord['forecast_time'] ?? '');
        $end   = $range[1] ?? ($rawRecord['forecast_time'] ?? '');

        return "{$rawRecord['sat']}:{$rawRecord['input_map']}:{$rawRecord['real']}:{$start}:{$end}";
    }
}
