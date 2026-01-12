<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\HEK;

use Psr\Log\LoggerInterface;

/**
 * HEK CME (CE) Event Processor
 *
 * Specialized processor for HEK Coronal Mass Ejection events.
 * Handles different FRM sources: CACTus, CDAW.
 *
 * @package    Helioviewer\EventsApi\Events\Processors\HEK
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class CEProcessor extends EventTypeProcessor
{
    public function __construct(?LoggerInterface $logger = null)
    {
        parent::__construct('CE', $logger);
    }

    /**
     * Build label array for CME events.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array Associative array of label key => value pairs
     */
    protected function buildLabelArray(array $rawRecord): array
    {
        $labelArray = [];
        $frmName = $rawRecord['frm_name'] ?? '';

        if ($frmName === 'CACTus (Computer Aided CME Tracking)') {
            $labelArray['Radial Lin. Vel.'] =
                ($rawRecord['cme_radiallinvel'] ?? '') . ' ± ' .
                ($rawRecord['cme_radiallinvelstddev'] ?? '') . ' ' .
                ($rawRecord['cme_radiallinvelunit'] ?? '');

            $labelArray['Angular Width'] =
                ($rawRecord['cme_angularwidth'] ?? '') . ' ' .
                ($rawRecord['cme_angularwidthunit'] ?? '');
        } elseif ($frmName === 'CDAW_GopalswamyYashiroFreeland') {
            $labelArray['Radial Lin. Vel.'] =
                ($rawRecord['cme_radiallinvel'] ?? '') . ' ' .
                ($rawRecord['cme_radiallinvelunit'] ?? '');

            $labelArray['Angular Width'] =
                ($rawRecord['cme_angularwidth'] ?? '') . ' ' .
                ($rawRecord['cme_angularwidthunit'] ?? '');

            $labelArray['Mass'] =
                ($rawRecord['cme_mass'] ?? '') . ' ' .
                ($rawRecord['cme_massunit'] ?? '');
        }

        return $labelArray;
    }
}
