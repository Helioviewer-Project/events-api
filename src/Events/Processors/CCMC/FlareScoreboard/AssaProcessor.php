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
        // CCMC bug: upstream sends -1 as the default value for a missing NOAA
        // response (LocationTime and both coordinates all -1). Skip NOAA in that
        // case and fall through to Catania/Model.
        $invalidNoaaResponseDueMinusOneDefaultValueCCMCbug =
            ($rawRecord['NOAALocationTime'] ?? null) == -1
            && ($rawRecord['NOAALatitude'] ?? null) == -1
            && ($rawRecord['NOAALongitude'] ?? null) == -1;

        // ASSA-specific: Try NOAA first, but don't require NOAARegionId
        if (!$invalidNoaaResponseDueMinusOneDefaultValueCCMCbug &&
            isset($rawRecord['NOAALatitude']) &&
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
    
    // collectRegions() is intentionally NOT overridden: ASSA links NOAA/Catania/
    // Model regions only when their RegionId is present (the base behavior). We
    // no longer attach a placeholder NOAA:UNK region for records that carry NOAA
    // coordinates but no NOAARegionId — coordinates still come from those values
    // in readCoordinates(), but the event is not grouped under a NOAA region it
    // doesn't actually have.
}
