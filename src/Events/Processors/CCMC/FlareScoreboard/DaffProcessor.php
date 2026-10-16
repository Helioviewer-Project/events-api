<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard;

use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use Helioviewer\EventsApi\Exception\JsocException;
use Helioviewer\EventsApi\Exception\UpstreamDownException;
use Helioviewer\EventsApi\Jsoc\HarpService;
use Helioviewer\EventsApi\Regions\ActiveRegionPositions;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;
use Psr\Log\LoggerInterface;

/**
 * DAFFS flare predictions.
 *
 * Rows name a NOAA region, or a HARP from HMI's near-real-time series in the
 * Model fields (DAFFS's own Model lat/lon is unreliable). A HARP that lists
 * exactly one NOAA region is placed as that NOAA region; otherwise as the HARP.
 *
 * @package    Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class DaffProcessor extends Processor
{
    /**
     * @param HarpService                $harpService HMI near-real-time HARP lookup (JSOC)
     * @param ActiveRegionPositions|null $positions   Where NOAA regions were
     * @param LoggerInterface|null       $logger      Logger
     * @param SentryClientInterface|null $sentry      Sentry client
     */
    public function __construct(
        private HarpService $harpService,
        ?ActiveRegionPositions $positions = null,
        ?LoggerInterface $logger = null,
        ?SentryClientInterface $sentry = null
    ) {
        parent::__construct($logger, $sentry, $positions);
    }

    /**
     * Handles DAFFS rows.
     *
     * @param SourceInterface $source    Data source
     * @param array           $rawRecord Raw record
     * @return bool
     */
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        return $source->getName() === 'FLARE_SCOREBOARD_DAFFS_REGIONS' && isset($rawRecord['start_window']);
    }

    /**
     * The row's NOAA region; else its HARP (as the HARP's NOAA region when it lists one); else CCMC's position.
     *
     * @param array $rawRecord Raw record
     * @return array|null Coordinate data, or null when the row has no position
     */
    protected function readCoordinates(array $rawRecord): ?array
    {
        if (isset($rawRecord['NOAARegionId']) && (int) $rawRecord['NOAARegionId'] > 0) {
            $coordinates = $this->readCoordinatesFromNOAARegion((int) $rawRecord['NOAARegionId'], $rawRecord['start_window']);
            if ($coordinates !== null) {
                return $coordinates;
            }
        }

        if (isset($rawRecord['ModelRegionId']) && (int) $rawRecord['ModelRegionId'] > 0) {
            $harpId = (int) $rawRecord['ModelRegionId'];
            try {
                $harp = $this->harpService->getLastCoordinateForHarp($harpId, $rawRecord['start_window']);
            } catch (JsocException $e) {
                throw new UpstreamDownException("JSOC is down: " . $e->getMessage(), null, $e);
            }
            $harpRegion = ['organization' => 'HARP', 'external_id' => (string) $harpId];

            // NOAA_ARS can be "MISSING", one number or several ("13690,13691"); only one is a NOAA region
            $noaaNumbers = array_values(array_filter(array_map('trim', explode(',', (string) ($harp['noaa_id'] ?? ''))), 'ctype_digit'));
            $via = $harp === null ? 'JSOC HARP' : 'JSOC HARP · ' . (!empty($harp['cached']) ? 'CACHED' : 'LIVE');
            $this->logger->info("Position | {$via} | HARP {$harpId} → " . match (true) {
                $harp === null => 'JSOC has no HARP record',
                count($noaaNumbers) === 1 => "NOAA {$noaaNumbers[0]}",
                default => 'HARP only (NOAA_ARS: ' . ($harp['noaa_id'] ?? 'none') . ')',
            });
            if (count($noaaNumbers) === 1) {
                $coordinates = $this->readCoordinatesFromNOAARegion((int) $noaaNumbers[0], $rawRecord['start_window'], [$harpRegion]);
                if ($coordinates !== null) {
                    return $coordinates;
                }
            }
            if ($harp !== null) {
                return [
                    'latitude' => (float) $harp['latitude'],
                    'longitude' => (float) $harp['longitude'],
                    'coordinate_time' => $harp['location_time'],
                    'source' => 'JSOC HARP ' . $harp['location_time'],
                    'regions' => [$harpRegion],
                ];
            }
        }

        return $this->readCoordinatesFromRow($rawRecord);
    }
}
