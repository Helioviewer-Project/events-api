<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard;

use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use Helioviewer\EventsApi\Exception\UpstreamFormatException;

/**
 * SIDC Operator flare predictions.
 *
 * Positions come from the NOAA region lookup (JSOC), not from the record:
 * CCMC's HAPI 3.0 server sends SIDC longitudes mirrored (East/West swapped)
 * for most forecasts issued since 2026-08-05, while older ones are right.
 *
 * Rows without a NOAA region carry no region or position (Catania copies of
 * a NOAA row, stripped by CCMC) and are skipped. A region-less row that does
 * carry NOAA position or Model fields, or a NOAA region JSOC has no position
 * for, is logged and sent to Sentry (UpstreamFormatException).
 *
 * @package    Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class SidcProcessor extends Processor
{
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        return $source->getName() === 'FLARE_SCOREBOARD_SIDC_Operator_REGIONS' && isset($rawRecord['start_window']);
    }

    protected function readCoordinates(array $rawRecord): ?array
    {
        $this->checkRegionless($rawRecord);

        // Region-less rows have nothing to place: skipped as "no position"
        if (!isset($rawRecord['NOAARegionId'])) {
            return null;
        }

        $coordinates = $this->readCoordinatesFromNOAARegion($rawRecord);
        if ($coordinates === null) {
            $this->report($rawRecord,
                "JSOC gave no position for NOAA region {$rawRecord['NOAARegionId']} before {$rawRecord['start_window']}.",
                "Check http://jsoc.stanford.edu/cgi-bin/ajax/jsoc_info?op=rs_list&ds=jsoc.noaa_active_regions[][{$rawRecord['NOAARegionId']}]&key=ObservationTime,LatitudeHG,LongitudeCM"
                . " — if JSOC is down, rerun later; if the region is missing, decide where SIDC positions come from for it.");
        }

        return $coordinates;
    }

    /**
     * A row without a NOAA region is expected to carry nothing else either
     * (a Catania copy of a NOAA row); one that does is reported.
     */
    private function checkRegionless(array $rawRecord): void
    {
        if (isset($rawRecord['NOAARegionId'])) {
            return;
        }

        $set = array_filter(
            ['NOAALocationTime', 'NOAALatitude', 'NOAALongitude', 'ModelRegionId', 'ModelLocationTime', 'ModelLatitude', 'ModelLongitude'],
            fn($k) => isset($rawRecord[$k])
        );
        if ($set) {
            $this->report($rawRecord,
                'SIDC row without a NOAA region has ' . implode(', ', $set) . '.',
                'Region-less rows are skipped; this one carries a region or position, so SidcProcessor must read it.');
        }
    }

    private function report(array $rawRecord, string $what, string $todo): void
    {
        $message = "Unexpected SIDC data: {$what} Update: {$todo} Row: " . json_encode($rawRecord);
        $this->logger->error($message);
        $this->sentry->capture(new UpstreamFormatException($message));
    }
}
