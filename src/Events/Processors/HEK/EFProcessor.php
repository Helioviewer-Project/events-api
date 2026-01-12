<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\HEK;

use Psr\Log\LoggerInterface;

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
    public function __construct(?LoggerInterface $logger = null)
    {
        parent::__construct('EF', $logger);
    }

    /**
     * Get timeline data for Emerging Flux events.
     * Older Emerging flux region module (version < 0.55) uses peak time for coordinate_time.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array ['start' => int, 'peak' => int, 'end' => int, 'coordinate_time' => int]
     */
    protected function getTimeLine(array $rawRecord): array
    {
        $start = strtotime($rawRecord['event_starttime']);
        $peakTime = !empty($rawRecord['event_peaktime']) ? strtotime($rawRecord['event_peaktime']) : false;
        $peak = ($peakTime !== false && $peakTime > 0) ? $peakTime : $start;
        $end = strtotime($rawRecord['event_endtime']);

        $frmName = $rawRecord['frm_name'] ?? '';
        $frmVersion = (float)($rawRecord['frm_versionnumber'] ?? 1.0);

        // Older Emerging flux region module versions use peak time
        $coordinateTime = ($frmName === 'Emerging flux region module' && $frmVersion < 0.55)
            ? $peak
            : $start;

        return [
            'start'           => $start,
            'peak'            => $peak,
            'end'             => $end,
            'coordinate_time' => $coordinateTime,
        ];
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
