<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator\HPC\Strategies;

/**
 * Stonyhurst (HGS) events — all CCMC sources.
 *
 * @package Helioviewer\EventsApi\Coordinator\HPC\Strategies
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class StonyhurstStrategy extends AbstractHeliographicStrategy
{
    /**
     * @return string
     */
    protected function system(): string
    {
        return 'stonyhurst';
    }

    /**
     * @param array $coordinates Keyed coords with lat, lon, coordinate_time
     * @param int $target Target timestamp
     * @return array Keyed hpc_x/hpc_y
     */
    protected function convert(array $coordinates, int $target): array
    {
        // Only the endpoint differs per system; the batching lives in the parent.
        return $this->coordinator->stonyhurstToHelioprojectiveBatch($coordinates, $target);
    }
}
