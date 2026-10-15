<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\WSA;

use Helioviewer\EventsApi\Events\Processors\BaseProcessor;

/**
 * Shared base for the WSA processors.
 *
 * Coronal holes and footpoints differ in geometry (multi-contour hole maps vs
 * single probability contours) and in how they pick a pin, but they come from
 * the same dashboard and describe the same kind of forecast window — so the
 * window timeline and the forecast wording live here. Their detail view and
 * dashboard link are built per request by ViewsAndLinks\WsaViews.
 *
 * @package Helioviewer\EventsApi\Events\Processors\WSA
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
abstract class Processor extends BaseProcessor
{
    /** Seconds a WSA event is active before its forecast_time. */
    protected const ACTIVE_BEFORE = 3600;

    /** Seconds a WSA event stays active after its forecast_time: maps come every 4–8 h. */
    protected const ACTIVE_AFTER = 8 * 3600;

    /**
     * The event timeline from the record's forecast window.
     *
     * WSA times are UTC ISO strings with no offset; the container runs in UTC.
     *
     * The event is active from ACTIVE_BEFORE before forecast_time until
     * ACTIVE_AFTER after it, roughly until the next map of the same kind (they
     * come every 4–8 h), so about one map of a kind shows at a time. Not the
     * API's forecast_range: that range is ~±1 h around the forecast, which is
     * the map's validity, not how long a viewer wants to see it. The
     * real range still reaches the client through the view ("Forecast window").
     * coordinate_time stays forecast_time, so widening this costs no rotation.
     * A record without a usable forecast_time falls back to the range start.
     *
     * @param array $rawRecord Raw WSA record
     * @return array{0:int,1:int,2:int} [start, peak, end] as unix timestamps
     */
    protected function timeline(array $rawRecord): array
    {
        $peak  = !empty($rawRecord['forecast_time']) ? (int) strtotime($rawRecord['forecast_time']) : 0;
        $range = $rawRecord['forecast_range'] ?? [];

        if ($peak === 0) {
            $peak = !empty($range[0]) ? (int) strtotime($range[0]) : 0;
        }

        return [$peak - self::ACTIVE_BEFORE, $peak, $peak + self::ACTIVE_AFTER];
    }

    /**
     * The forecast time as it appears in labels, e.g. "Forecast: 2026-07-08 12:00".
     *
     * @param int $peak Forecast time as a unix timestamp
     * @return string
     */
    protected function forecastTag(int $peak): string
    {
        return 'Forecast: ' . gmdate('Y-m-d H:i', $peak);
    }
}
