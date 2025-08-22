<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard;

use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use Helioviewer\EventsApi\Jsoc\HarpService;
use Helioviewer\EventsApi\Jsoc\NoaaService;
use Helioviewer\EventsApi\Exception\CoordinateResolutionException;

/**
 * DAFF Solar Flare Event Processor
 *
 * This processor handles the transformation and normalization of DAFF solar flare
 * event data from the Community Coordinated Modeling Center (CCMC). DAFF (Data
 * Archive for Flare Forecasting) provides specialized solar flare prediction and
 * analysis data that requires custom processing logic.
 *
 * @package    Helioviewer\EventsApi\Collector\Processors\CCMC\FlareScoreboard
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class DaffProcessor extends Processor
{
    private HarpService $harpService;
    private NoaaService $noaaService;
    
    public function __construct(
        HarpService $harpService,
        NoaaService $noaaService,
        ?\Psr\Log\LoggerInterface $logger = null
    ) {
        parent::__construct($logger);
        $this->harpService = $harpService;
        $this->noaaService = $noaaService;
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

    /**
     * Read coordinates from raw record - simplified version.
     *
     * Checks for HARP fields in raw data only.
     *
     * @param array $rawRecord The raw event data
     * @return array|null Array with coordinate data or null if not found
     */
    protected function readCoordinates(array $rawRecord): ?array
    {
        // Check if we have HARP coordinates directly in the raw record (don't require HARPRegionId)
        if (isset($rawRecord['HARPLatitude']) && 
            isset($rawRecord['HARPLongitude']) &&
            $rawRecord['HARPLatitude'] !== '' &&
            $rawRecord['HARPLongitude'] !== '') {
            
            // Handle HARPRegionId - use it if available, otherwise use 'UNK'
            $regionId = (isset($rawRecord['HARPRegionId']) && $rawRecord['HARPRegionId'] !== '') ? $rawRecord['HARPRegionId'] : 'UNK';
            $this->logger->info("DAFF: Using HARP coordinates from raw record for region {$regionId}");
            
            return [
                'latitude' => (float) $rawRecord['HARPLatitude'],
                'longitude' => (float) $rawRecord['HARPLongitude'],
                'coordinate_time' => $rawRecord['start_window'],
                'source' => 'HARP ' . $regionId . '|RawRecord'
            ];
        }
        
        // No HARP coordinates found, try parent implementation
        $this->logger->debug("DAFF: No HARP coordinates found, trying parent implementation");
        $coordinates = parent::readCoordinates($rawRecord);
        
        if ($coordinates !== null) {
            return $coordinates;
        }
        
        // Parent failed, try NOAA service if NOAARegionId is available
        if (isset($rawRecord['NOAARegionId'])) {
            
            $eventDateTime = $rawRecord['start_window'] ?? date('Y-m-d H:i:s');
            $this->logger->info("DAFF: Trying NOAA service for region {$rawRecord['NOAARegionId']} to find closest coordinates before {$eventDateTime}");
            
            $noaaId = (int) $rawRecord['NOAARegionId'];
            $noaaData = $this->noaaService->getLastCoordinateForNoaa($noaaId, $eventDateTime);
            
            if ($noaaData) {
                $this->logger->info("DAFF: Found coordinates from NOAA service for region {$noaaId}");
                return [
                    'latitude' => (float) $noaaData['latitude'],
                    'longitude' => (float) $noaaData['longitude'],
                    'coordinate_time' => $noaaData['location_time'] ?? $eventDateTime,
                    'source' => 'NOAA ' . $noaaId . '| JSOC NOAAService'
                ];
            } else {
                $this->logger->debug("DAFF: No data found from NOAA service for region {$noaaId}");
            }
        }
        
        return null;
    }
    
    /**
     * Collect all region information from raw record for DAFF
     * 
     * @param array $rawRecord The raw event data
     * @return array Array of region info arrays
     */
    protected function collectRegions(array $rawRecord): array
    {
        $regions = [];
        
        // Check for NOAA region
        if (isset($rawRecord['NOAARegionId']) && $rawRecord['NOAARegionId'] !== '') {
            $regions[] = [
                'organization' => 'NOAA',
                'external_id' => (string) $rawRecord['NOAARegionId']
            ];
            $this->logger->debug("DAFF: Added NOAA region: {$rawRecord['NOAARegionId']}");
        }
        
        // Check for HARP region
        if (isset($rawRecord['HARPRegionId']) && $rawRecord['HARPRegionId'] !== '') {
            $regions[] = [
                'organization' => 'HARP',
                'external_id' => (string) $rawRecord['HARPRegionId']
            ];
            $this->logger->debug("DAFF: Added HARP region: {$rawRecord['HARPRegionId']}");
        }
        
        return $regions;
    }
}
