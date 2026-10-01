<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Sources\WSA;

use Helioviewer\EventsApi\Utils\TimeRange;

/**
 * WSA coronal-hole boundaries (Helio-Carrington).
 *
 * Discovers input_maps from the capabilities endpoint and makes ONE request per
 * map: AGONG is the twelve-member ensemble and the API serves every realization
 * in a single `real=all` reply, keyed by realization number; GONGZ has one
 * member and is asked for `real=0`. Each realization's value is a list of
 * forecast windows; every WINDOW becomes one raw record carrying all of its
 * contour polygons (the processor turns them into a multi-polygon footprint).
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
     * all six, and AGONG's realizations arrive in one `real=all` reply: 78
     * requests a day become 2. It is still recorded in the raw
     * record and the remote_id (a constant segment) so coronal-hole ids stay
     * parallel to the footpoint ids and the sidecar keeps the provenance.
     */
    private const SAT = 'SWPC_REALTIME';

    public function getName(): string
    {
        return 'WSA_CORONAL_HOLES';
    }

    /** The ensemble map; the only one the API accepts `real=all` for (GONGZ answers 500). */
    private const ENSEMBLE_MAP = 'AGONG';

    /** Realizations of the ensemble, for the one-request-per-member fallback. */
    private const ENSEMBLE_REALIZATIONS = 12;

    public function fetchRawData(TimeRange $range): array
    {
        $dates = $this->dateParams($range);

        $caps      = $this->capabilities(self::API_BASE . '/load_helioviewer_coronal_hole_capabilities');
        $inputMaps = $caps['input_maps'] ?? [];

        $records = [];

        foreach ($inputMaps as $inputMap) {
            foreach ($this->windowsByRealization($dates, $inputMap) as $real => $windows) {
                foreach ($windows as $window) {
                    $record = $this->windowRecord($window, $inputMap, $real);
                    if ($record !== null) {
                        $records[] = $record;
                    }
                }
            }
        }

        return $records;
    }

    /**
     * Forecast windows for one input map, keyed by realization.
     *
     * AGONG is fetched once with `real=all`, which returns `{"0": [...], ...,
     * "11": [...]}` — each value identical to what `real=N` alone returns. Should
     * that reply ever come back as a plain list (the shape the endpoint had
     * before `real=all` existed), fall back to one request per realization so
     * collection keeps working. GONGZ has a single member and is asked for it
     * directly.
     *
     * @param array{start_date:string, end_date:string} $dates
     * @return array<int, list<mixed>> realization => forecast windows
     */
    private function windowsByRealization(array $dates, string $inputMap): array
    {
        if ($inputMap !== self::ENSEMBLE_MAP) {
            return [0 => $this->fetchCoronalHoles($dates, $inputMap, '0')];
        }

        $reply = $this->fetchCoronalHoles($dates, $inputMap, 'all');

        if ($this->isKeyedByRealization($reply)) {
            $byReal = [];
            foreach ($reply as $real => $windows) {
                $byReal[(int) $real] = is_array($windows) ? $windows : [];
            }
            ksort($byReal);

            return $byReal;
        }

        $byReal = [];
        for ($real = 0; $real < self::ENSEMBLE_REALIZATIONS; $real++) {
            $byReal[$real] = $this->fetchCoronalHoles($dates, $inputMap, (string) $real);
        }

        return $byReal;
    }

    /**
     * One GET against the coronal-holes endpoint, followed by the inter-request
     * pause the WSA API needs.
     *
     * @param array{start_date:string, end_date:string} $dates
     * @param string $real A realization number, or `all`
     * @return array The decoded reply: a list of windows, or realization => windows
     */
    private function fetchCoronalHoles(array $dates, string $inputMap, string $real): array
    {
        $url = self::API_BASE . '/load_helioviewer_coronal_holes?' . http_build_query(
            $dates + ['input_map' => $inputMap, 'sat' => self::SAT, 'real' => $real]
        );

        $reply = $this->makeJsonRequest($url);
        usleep($this->sleepMicros);

        return $reply;
    }

    /**
     * Tell a `real=all` reply from a single-realization one. The keys cannot do
     * it: json_decode turns `{"0": ..., "11": ...}` into a list-shaped array. The
     * values can — a realization's value is a LIST of windows (possibly empty),
     * whereas a window is an object with string keys. An empty reply is treated
     * as the single-realization shape.
     */
    private function isKeyedByRealization(array $reply): bool
    {
        if ($reply === []) {
            return false;
        }

        foreach ($reply as $value) {
            if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
                return false;
            }
        }

        return true;
    }

    /**
     * One raw record for a forecast window: ALL of the window's contours grouped
     * together, so the processor builds one Event with a multi-polygon footprint.
     * Null when the window carries no usable contour.
     */
    private function windowRecord(mixed $window, string $inputMap, int $real): ?array
    {
        if (!is_array($window)) {
            return null;
        }

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
            return null;
        }

        return [
            'product'        => 'coronal_hole',
            'sat'            => self::SAT,
            'input_map'      => $inputMap,
            'real'           => $real,
            'forecast_time'  => $window['forecast_time'] ?? null,
            'forecast_range' => $window['forecast_range'] ?? null,
            'contours'       => $contours,
        ];
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
