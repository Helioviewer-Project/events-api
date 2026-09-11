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
 * window timeline, the dashboard links and the forecast wording live here.
 *
 * @package Helioviewer\EventsApi\Events\Processors\WSA
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
abstract class Processor extends BaseProcessor
{
    protected const DASHBOARD_URL = 'https://ccmc.gsfc.nasa.gov/wsa-dashboard/';
    protected const DASHBOARD_COMMUNITY_URL = 'https://ccmc.gsfc.nasa.gov/community-tools/WSA-Dashboard/';

    /**
     * The event timeline from the record's forecast window.
     *
     * WSA times are UTC ISO strings with no offset; the container runs in UTC.
     * A record without a usable forecast_time falls back to the window start.
     *
     * @param array $rawRecord Raw WSA record
     * @return array{0:int,1:int,2:int} [start, peak, end] as unix timestamps
     */
    protected function timeline(array $rawRecord): array
    {
        $peak  = !empty($rawRecord['forecast_time']) ? (int) strtotime($rawRecord['forecast_time']) : 0;
        $range = $rawRecord['forecast_range'] ?? [];
        $start = !empty($range[0]) ? (int) strtotime($range[0]) : $peak;
        $end   = !empty($range[1]) ? (int) strtotime($range[1]) : $peak;

        if ($peak === 0) {
            $peak = $start;
        }

        return [$start, $peak, $end];
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

    /**
     * The forecast window as shown in the detail view, e.g. "<start> to <end>".
     *
     * @param array $rawRecord Raw WSA record
     * @return string|null Null when the record carries no window
     */
    protected function forecastWindow(array $rawRecord): ?string
    {
        $range = $rawRecord['forecast_range'] ?? [];

        return is_array($range) ? implode(' to ', $range) : null;
    }

    /**
     * Link stored in the event's links sidecar.
     *
     * @return array{url:string,text:string}
     */
    protected function dashboardLink(): array
    {
        return [
            'url'  => self::DASHBOARD_URL,
            'text' => 'CCMC WSA Dashboard',
        ];
    }
}
