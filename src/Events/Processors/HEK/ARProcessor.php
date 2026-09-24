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
     * SPoCA reports each region as found in the map at event_endtime, valid
     * since the previous map at event_starttime; the ivorn is named after the
     * end time (..._20110218T190001_2). Other FRMs report at start.
     *
     * @param array $rawRecord Raw event data from HEK
     * @param int $start event_starttime
     * @param int $peak event_peaktime, or start when absent
     * @param int $end event_endtime
     * @return int
     */
    protected function coordinateTime(array $rawRecord, int $start, int $peak, int $end): int
    {
        $frmName = $rawRecord['frm_name'] ?? '';

        return ($frmName === 'SPoCA') ? $end : $start;
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
