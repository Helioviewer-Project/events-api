<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard;

use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use Helioviewer\EventsApi\Exception\SkippedRecordException;

/**
 * AMOS flare predictions.
 *
 * CCMC sends every AMOS forecast twice: a NOAA row, and a Model row with the
 * same probabilities and position whose "region" is a counter (0, 1, 2…).
 * Model rows are skipped as duplicates; NOAA rows follow the base rule.
 *
 * @package    Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class AmosProcessor extends Processor
{
    /**
     * Handles AMOS rows.
     *
     * @param SourceInterface $source    Data source
     * @param array           $rawRecord Raw record
     * @return bool
     */
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        return $source->getName() === 'FLARE_SCOREBOARD_AMOS_v1_REGIONS' && isset($rawRecord['start_window']);
    }

    /**
     * NOAA rows by the base rule; Model rows are skipped as repeats.
     *
     * @param array $rawRecord Raw record
     * @return array|null Coordinate data
     * @throws SkippedRecordException For a Model row
     */
    protected function readCoordinates(array $rawRecord): ?array
    {
        if (!isset($rawRecord['NOAARegionId']) && isset($rawRecord['ModelRegionId'])) {
            throw new SkippedRecordException("AMOS Model {$rawRecord['ModelRegionId']} repeats a NOAA row of the same issue");
        }

        return parent::readCoordinates($rawRecord);
    }
}
