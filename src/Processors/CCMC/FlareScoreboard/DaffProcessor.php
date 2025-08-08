<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Processors\CCMC\FlareScoreboard;

use Helioviewer\EventsApi\Sources\SourceInterface;

/**
 * DAFF Solar Flare Event Processor
 *
 * This processor handles the transformation and normalization of DAFF solar flare
 * event data from the Community Coordinated Modeling Center (CCMC). DAFF (Data
 * Archive for Flare Forecasting) provides specialized solar flare prediction and
 * analysis data that requires custom processing logic.
 *
 * @package    Helioviewer\EventsApi\Processors\CCMC\FlareScoreboard
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class DaffProcessor extends Processor
{
    public function __construct()
    {
        // Constructor simplified - coordinate resolution handled by injected resolver
    }
    /**
     * Determines if this processor can handle DAFF source data.
     *
     * @param SourceInterface $source The data source interface
     * @param array $rawRecord The raw event data record from the source
     *
     * @return bool True if this processor can handle the data, false otherwise
     */
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        return $source->getName() === 'FLARE_SCOREBOARD_DAFFS_REGIONS' && isset($rawRecord['start_window']);
    }
}
