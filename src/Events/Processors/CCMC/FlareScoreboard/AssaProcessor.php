<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard;

use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use Psr\Log\LoggerInterface;

/**
 * ASSA Solar Flare Event Processor
 *
 * This processor handles the transformation and normalization of ASSA solar flare
 * prediction data from the Community Coordinated Modeling Center (CCMC).
 *
 * @package    Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class AssaProcessor extends Processor
{
    /**
     * Determines if this processor can handle ASSA source data.
     *
     * @param SourceInterface $source The data source interface
     * @param array $rawRecord The raw event data record from the source
     *
     * @return bool True if this processor can handle the data, false otherwise
     */
    public function canProcess(SourceInterface $source, array $rawRecord): bool
    {
        return $source->getName() === 'FLARE_SCOREBOARD_ASSA_1_REGIONS' && isset($rawRecord['start_window']);
    }

    /**
     * Read coordinates from raw record with ASSA-specific logic.
     *
     * @param array $rawRecord The raw event data
     * @return array|null Array with coordinate data or null if no valid coordinates found
     */
    protected function readCoordinates(array $rawRecord): ?array
    {
        // ASSA-specific: Try NOAA first, but don't require NOAARegionId
        if (isset($rawRecord['NOAALatitude']) && 
            isset($rawRecord['NOAALongitude']) &&
            $rawRecord['NOAALatitude'] !== '' &&
            $rawRecord['NOAALongitude'] !== '') {
            
            // Handle 0 values correctly - use isset instead of !empty
            $regionId = (isset($rawRecord['NOAARegionId']) && $rawRecord['NOAARegionId'] !== '') ? $rawRecord['NOAARegionId'] : 'Unknown';
            $this->logger->info("ASSA: Using NOAA coordinates for region {$regionId}");
            
            return [
                'latitude' => (float) $rawRecord['NOAALatitude'],
                'longitude' => (float) $rawRecord['NOAALongitude'],
                'coordinate_time' => $rawRecord['NOAALocationTime'] ?? null,
                'source' => 'ASSA NOAA ' . $regionId . '|RawRecord'
            ];
        }
        
        // If NOAA doesn't exist, try parent's implementation but skip NOAA since we already checked it
        $this->logger->debug("ASSA: NOAA coordinates not found, trying Catania and Model fields");
        
        // Try Catania and Model fields through parent's readCoordinatesFromField method
        $possibleFields = ['Catania', 'Model'];
        
        foreach ($possibleFields as $field) {
            $coordinates = $this->readCoordinatesFromField($rawRecord, $field);
            if ($coordinates !== null) {
                $this->logger->info("ASSA: Successfully resolved coordinates from {$field} field");
                return $coordinates;
            }
        }
        
        // No coordinates found
        return null;
    }
    
    /**
     * Collect all region information from raw record for ASSA
     * 
     * @param array $rawRecord The raw event data
     * @return array Array of region info arrays
     */
    protected function collectRegions(array $rawRecord): array
    {
        // First get all regions from parent implementation
        $regions = parent::collectRegions($rawRecord);
        
        // Use array_filter to check if NOAA region exists
        $noaaRegions = array_filter($regions, function($region) {
            return $region['organization'] === 'NOAA';
        });
        
        // If NOAA region already exists, return as-is
        if (!empty($noaaRegions)) {
            return $regions;
        }
        
        // No NOAA region found - check if NOAA coordinates exist
        if (isset($rawRecord['NOAALatitude']) && 
            isset($rawRecord['NOAALongitude']) &&
            $rawRecord['NOAALatitude'] !== '' &&
            $rawRecord['NOAALongitude'] !== '') {
            
            // Add NOAA with 'UNK' id
            $regions[] = [
                'organization' => 'NOAA',
                'external_id' => 'UNK'
            ];
            
            $this->logger->debug("ASSA: Added NOAA region with ID: UNK (coordinates exist but no RegionId)");
        }
        
        return $regions;
    }
}
