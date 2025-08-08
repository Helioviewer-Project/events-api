<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Collector\Coordinate;

use HelioviewerEventInterface\Util\LocationParser;

/**
 * Field-Based Coordinate Resolver
 *
 * Resolves coordinates by reading directly from raw record fields.
 * Supports multiple coordinate sources with configurable priority order.
 *
 * @package Helioviewer\EventsApi\Collector\Coordinate
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class FieldBasedResolver implements ResolverInterface
{
    /**
     * @param array $sourcePriority Priority order for coordinate sources
     */
    public function __construct(
        private array $sourcePriority = ['NOAA', 'Catania', 'Model']
    ) {}

    /**
     * Resolve coordinates from raw record fields.
     *
     * @param array $rawRecord The raw event data
     *
     * @return array|null Array with coordinate data or null if resolution fails
     */
    public function resolve(array $rawRecord): ?array
    {
        // Default values
        $defaults = [
            'latitude' => null,
            'longitude' => null,
            'regionId' => null,
            'locationTime' => null,
            'source' => null
        ];
        
        // Iterate through sources in priority order
        foreach ($this->sourcePriority as $source) {
            $regionKey = $source . 'RegionId';
            $latKey = $source . 'Latitude';
            $lonKey = $source . 'Longitude';
            $timeKey = $source . 'LocationTime';
            
            // Check if this source has the required data
            if (!empty($rawRecord[$regionKey]) && 
                isset($rawRecord[$latKey]) && 
                isset($rawRecord[$lonKey]) &&
                $rawRecord[$latKey] !== '' &&
                $rawRecord[$lonKey] !== '') {
                
                $lat = (float) $rawRecord[$latKey];
                $lon = (float) $rawRecord[$lonKey];
                
                if (LocationParser::IsValidLatitudeLongitude($lat, $lon)) {
                    return [
                        'latitude' => $lat,
                        'longitude' => $lon,
                        'region' => $rawRecord[$regionKey],  // Raw region ID from field
                        'locationTime' => $rawRecord[$timeKey] ?? null,
                        'source' => 'rawRecord'
                    ];
                }
            }
        }
        
        return null;
    }

    /**
     * Check if this resolver can process the given raw record.
     *
     * @param array $rawRecord The raw event data
     *
     * @return bool True if any of the priority sources have coordinate data
     */
    public function canResolve(array $rawRecord): bool
    {
        foreach ($this->sourcePriority as $source) {
            $regionKey = $source . 'RegionId';
            $latKey = $source . 'Latitude';
            $lonKey = $source . 'Longitude';
            
            if (!empty($rawRecord[$regionKey]) && 
                isset($rawRecord[$latKey]) && 
                isset($rawRecord[$lonKey]) &&
                $rawRecord[$latKey] !== '' &&
                $rawRecord[$lonKey] !== '') {
                return true;
            }
        }
        
        return false;
    }
}