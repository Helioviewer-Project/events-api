<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Collector\Coordinate;

use HelioviewerEventInterface\Util\LocationParser;

/**
 * Model Field-Based Coordinate Resolver
 *
 * Resolves coordinates by reading Model fields directly from raw record.
 * Looks for ModelRegionId, ModelLatitude, ModelLongitude, and ModelLocationTime.
 *
 * @package Helioviewer\EventsApi\Collector\Coordinate
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class ModelFieldResolver implements ResolverInterface
{
    /**
     * Resolve coordinates from Model fields in raw record.
     *
     * @param array $rawRecord The raw event data
     *
     * @return array|null Array with coordinate data or null if resolution fails
     */
    public function resolve(array $rawRecord): ?array
    {
        $regionKey = 'ModelRegionId';
        $latKey = 'ModelLatitude';
        $lonKey = 'ModelLongitude';
        $timeKey = 'ModelLocationTime';
        
        // Check if Model fields have the required data
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
        
        return null;
    }

    /**
     * Check if this resolver can process the given raw record.
     *
     * @param array $rawRecord The raw event data
     *
     * @return bool True if Model fields have coordinate data
     */
    public function canResolve(array $rawRecord): bool
    {
        $regionKey = 'ModelRegionId';
        $latKey = 'ModelLatitude';
        $lonKey = 'ModelLongitude';
        
        return !empty($rawRecord[$regionKey]) && 
               isset($rawRecord[$latKey]) && 
               isset($rawRecord[$lonKey]) &&
               $rawRecord[$latKey] !== '' &&
               $rawRecord[$lonKey] !== '';
    }
}