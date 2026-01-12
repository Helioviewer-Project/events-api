<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\HEK;

use Psr\Log\LoggerInterface;

/**
 * HEK Coronal Hole (CH) Event Processor
 *
 * Specialized processor for HEK Coronal Hole events.
 * Handles different FRM sources: LMSAL forecaster, SPoCA.
 *
 * @package    Helioviewer\EventsApi\Events\Processors\HEK
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class CHProcessor extends EventTypeProcessor
{
    public function __construct(?LoggerInterface $logger = null)
    {
        parent::__construct('CH', $logger);
    }

    /**
     * Get timeline data for Coronal Hole events.
     * SPoCA events use end time for coordinate_time.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array ['start' => int, 'peak' => int, 'end' => int, 'coordinate_time' => int]
     */
    protected function getTimeLine(array $rawRecord): array
    {
        $start = strtotime($rawRecord['event_starttime']);
        $peak = strtotime($rawRecord['event_peaktime'] ?? $rawRecord['event_starttime']);
        $end = strtotime($rawRecord['event_endtime']);

        $frmName = $rawRecord['frm_name'] ?? '';
        $coordinateTime = ($frmName === 'SPoCA') ? $end : $start;

        return [
            'start'           => $start,
            'peak'            => $peak,
            'end'             => $end,
            'coordinate_time' => $coordinateTime,
        ];
    }

    /**
     * Build label array for Coronal Hole events.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array Associative array of label key => value pairs
     */
    protected function buildLabelArray(array $rawRecord): array
    {
        $labelArray = [];
        $frmName = $rawRecord['frm_name'] ?? '';

        if ($frmName === 'LMSAL forecaster + SSW PFSS package' ||
            $frmName === 'LMSAL forecaster 2 + SSW PFSS package') {
            $areaValue = str_replace(
                '+',
                '',
                sprintf('%.1e', (float)($rawRecord['area_atdiskcenter'] ?? 0))
            );
            $areaUnit = str_replace('2', '²', $rawRecord['area_unit'] ?? '');
            $labelArray['Area at Disk Center'] = $areaValue . ' ' . $areaUnit;
        } elseif ($frmName === 'SPoCA') {
            $tmpArr = explode('_', $rawRecord['frm_specificid'] ?? '');
            $labelArray['SPoCA Identifier'] = 'SPoCA ' . ltrim(array_pop($tmpArr), '0');
        }

        return $labelArray;
    }
}
