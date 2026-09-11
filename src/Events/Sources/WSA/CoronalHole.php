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
    /**
     * Coronal-hole maps describe the Sun, not the observer, so the API returns
     * the same contours whatever `sat` is passed — confirmed by CCMC's WSA
     * developer 2026-08-31. `sat` stays a required parameter on their side (the
     * API is already published), so we send one fixed value instead of looping
     * all six: 78 requests a day become 13. It is still recorded in the raw
     * record and the remote_id (a constant segment) so coronal-hole ids stay
     * parallel to the footpoint ids and the sidecar keeps the provenance.
     */
    private const SAT = 'SWPC_REALTIME';

    public function getName(): string
    {
        return 'WSA_CORONAL_HOLES';
    }

    public function fetchRawData(TimeRange $range): array
    {
        $dates = $this->dateParams($range);

        $caps      = $this->capabilities(self::API_BASE . '/load_helioviewer_coronal_hole_capabilities');
        $inputMaps = $caps['input_maps'] ?? [];

        $records = [];

        foreach ($inputMaps as $inputMap) {
            $reals = ($inputMap === 'AGONG') ? range(0, 11) : [0];

            foreach ($reals as $real) {
                $url = self::API_BASE . '/load_helioviewer_coronal_holes?' . http_build_query(
                    $dates + ['input_map' => $inputMap, 'sat' => self::SAT, 'real' => $real]
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
                        'sat'            => self::SAT,
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

        // Records stored before `sat` was added back lack the key; it is a
        // constant, so defaulting to SAT yields the same id either way.
        $sat = $rawRecord['sat'] ?? self::SAT;

        return "{$sat}:{$rawRecord['input_map']}:{$rawRecord['real']}:{$start}:{$end}";
    }
}
