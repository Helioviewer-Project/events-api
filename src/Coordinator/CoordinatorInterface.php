<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

/**
 * Coordinator Interface
 * 
 * Interface for coordinate transformation services
 * 
 * @package    Helioviewer\EventsApi\Coordinator
 * @since      1.0.0
 */
interface CoordinatorInterface
{
    /**
     * Rotate Stonyhurst (HGS) coordinates to Helioprojective Cartesian (HPC) at target time
     * 
     * @param float $latitude HGS latitude
     * @param float $longitude HGS longitude
     * @param string $coordinateTime Coordinate time in ISO 8601 format
     * @param string $targetTime Target time in ISO 8601 format
     * @return array Array with 'hpc_x' and 'hpc_y' keys containing Helioprojective Cartesian coordinates
     * @throws \Exception If coordinate transformation fails
     */
    public function rotate(
        float $latitude,
        float $longitude,
        string $coordinateTime,
        string $targetTime
    ): array;
    
    /**
     * Batch rotate multiple Stonyhurst coordinates to Helioprojective Cartesian
     * 
     * @param array $events Array of events with coordinate data
     * @param int|string $targetTimestamp Target time for coordinate rotation
     * @return array Dictionary with event ID as key and rotated HPC coordinates as value
     */
    public function rotateAll(array $events, $targetTimestamp): array;
}