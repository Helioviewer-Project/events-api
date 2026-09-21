<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\HEK;

use Psr\Log\LoggerInterface;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;

/**
 * HEK Flare (FL) Event Processor
 *
 * Specialized processor for HEK Flare events.
 * Handles different FRM sources: SEC standard, Flare Detective, SWPC.
 *
 * @package    Helioviewer\EventsApi\Events\Processors\HEK
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class FlareProcessor extends EventTypeProcessor
{
    public function __construct(?LoggerInterface $logger = null, ?SentryClientInterface $sentry = null)
    {
        parent::__construct('FL', $logger, $sentry);
    }

    /**
     * Build label array for Flare events.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array Associative array of label key => value pairs
     */
    protected function buildLabelArray(array $rawRecord): array
    {
        $labelArray = [];
        $frmName = $rawRecord['frm_name'] ?? '';

        if ($frmName === 'SEC standard') {
            $labelArray['GOES Class'] = $rawRecord['fl_goescls'] ?? '';
        } elseif ($frmName === 'Flare Detective - Trigger Module') {
            $peakFlux = round((float)($rawRecord['fl_peakflux'] ?? 0), 1);
            $peakFluxUnit = $rawRecord['fl_peakfluxunit'] ?? '';
            $labelArray['Peak Flux'] = $peakFlux . ' ' . $peakFluxUnit;
        } elseif ($frmName === 'SWPC') {
            $labelArray['GOES Class'] = $rawRecord['fl_goescls'] ?? '';
        }

        return $labelArray;
    }
}
