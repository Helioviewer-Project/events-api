<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\HEK;

use Psr\Log\LoggerInterface;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;

/**
 * HEK Emerging Flux (EF) Event Processor
 *
 * Specialized processor for HEK Emerging Flux events.
 * Handles the Emerging flux region module FRM source.
 *
 * @package    Helioviewer\EventsApi\Events\Processors\HEK
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class EFProcessor extends EventTypeProcessor
{
    public function __construct(?LoggerInterface $logger = null, ?SentryClientInterface $sentry = null)
    {
        parent::__construct('EF', $logger, $sentry);
    }

    /**
     * Build label array for Emerging Flux events.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array Associative array of label key => value pairs
     */
    protected function buildLabelArray(array $rawRecord): array
    {
        $labelArray = [];
        $frmName = $rawRecord['frm_name'] ?? '';

        if ($frmName === 'Emerging flux region module') {
            if (isset($rawRecord['area_atdiskcenter']) && $rawRecord['area_atdiskcenter'] !== null &&
                isset($rawRecord['area_atdiskcenteruncert']) && $rawRecord['area_atdiskcenteruncert'] !== null) {
                $areaValue = str_replace('+', '', sprintf('%.1e', (float)$rawRecord['area_atdiskcenter']));
                $areaUncert = str_replace('+', '', sprintf('%.1e', (float)$rawRecord['area_atdiskcenteruncert']));
                $areaUnit = str_replace('2', '²', $rawRecord['area_unit'] ?? '');
                $labelArray['Area at Disk Center'] = $areaValue . ' ± ' . $areaUncert . ' ' . $areaUnit;
            }

            if (isset($rawRecord['ef_pospeakfluxonsetrate']) && $rawRecord['ef_pospeakfluxonsetrate'] !== null &&
                isset($rawRecord['ef_onsetrateunit']) && $rawRecord['ef_onsetrateunit'] !== null) {
                $labelArray['Peak Pos. Flux Onset'] =
                    round((float)$rawRecord['ef_pospeakfluxonsetrate'], 1) . ' ' . $rawRecord['ef_onsetrateunit'];
            }

            if (isset($rawRecord['ef_negpeakfluxonsetrate']) && $rawRecord['ef_negpeakfluxonsetrate'] !== null &&
                isset($rawRecord['ef_onsetrateunit']) && $rawRecord['ef_onsetrateunit'] !== null) {
                $labelArray['Peak Neg. Flux Onset'] =
                    round((float)$rawRecord['ef_negpeakfluxonsetrate'], 1) . ' ' . $rawRecord['ef_onsetrateunit'];
            }
        }

        return $labelArray;
    }
}
