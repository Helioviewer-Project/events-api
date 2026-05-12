<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\HEK;

use Psr\Log\LoggerInterface;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;

/**
 * HEK Active Region (AR) Event Processor
 *
 * Specialized processor for HEK Active Region events.
 * Handles different FRM sources: HMI SHARP, NOAA SWPC Observer, SPoCA, SMART.
 *
 * @package    Helioviewer\EventsApi\Events\Processors\HEK
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class ARProcessor extends EventTypeProcessor
{
    public function __construct(?LoggerInterface $logger = null, ?SentryClientInterface $sentry = null)
    {
        parent::__construct('AR', $logger, $sentry);
    }

    /**
     * Get timeline data for Active Region events.
     * SPoCA events use end time for coordinate_time.
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
        $coordinateTime = ($frmName === 'SPoCA') ? $end : $start;

        return [
            'start'           => $start,
            'peak'            => $peak,
            'end'             => $end,
            'coordinate_time' => $coordinateTime,
        ];
    }

    /**
     * Build label array for Active Region events.
     *
     * @param array $rawRecord Raw event data from HEK
     * @return array Associative array of label key => value pairs
     */
    protected function buildLabelArray(array $rawRecord): array
    {
        $labelArray = [];
        $frmName = $rawRecord['frm_name'] ?? '';

        if ($frmName === 'HMI SHARP') {
            $labelArray['HMI SHARP Identifier'] = 'HMI SHARP ' . ($rawRecord['frm_specificid'] ?? '');
        } elseif ($frmName === 'NOAA SWPC Observer') {
            $labelArray['NOAA Number'] = 'NOAA ' . ($rawRecord['ar_noaanum'] ?? '');

            $arMtwilsoncls = $rawRecord['ar_mtwilsoncls'] ?? '';
            if (preg_match_all('/(ALPHA|BETA|GAMMA)/', $arMtwilsoncls, $matches) > 0) {
                $arMtwilsoncls = implode('', $matches[0]);
                $arMtwilsoncls = str_replace(
                    ['ALPHA', 'BETA', 'GAMMA'],
                    ['α', 'β', 'γ'],
                    $arMtwilsoncls
                );
            }
            $labelArray['Mt. Wilson Class.'] = $arMtwilsoncls;
        } elseif ($frmName === 'SPoCA') {
            $tmpArr = explode('_', $rawRecord['frm_specificid'] ?? '');
            $labelArray['SPoCA Identifier'] = 'SPoCA ' . ltrim(array_pop($tmpArr), '0');
        } elseif ($frmName === 'SolarMonitor Active Region Tracker (SMART)') {
            $labelArray['SMART Identifier'] = 'SMART ' . ($rawRecord['frm_specificid'] ?? '');
        }

        return $labelArray;
    }
}
