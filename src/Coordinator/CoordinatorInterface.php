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
     * @throws CoordinatorException If coordinate transformation fails
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
     * @param array $coordinateArray Array of coordinate data with 'lat', 'lon', 'coordinate_time' keys
     * Example: [
     *     ['lat' => 15.0, 'lon' => -2.0, 'coordinate_time' => 1715428800],
     *     ['lat' => -10.0, 'lon' => 45.0, 'coordinate_time' => 1715432400]
     * ]
     * @param int|string $targetTimestamp Target time for coordinate rotation
     * @return array Array of rotated coordinates in same order as input
     * Example: [
     *     ['hpc_x' => 123.45, 'hpc_y' => -67.89],
     *     ['hpc_x' => -234.56, 'hpc_y' => 78.90]
     * ]
     */
    public function rotateAll(array $coordinateArray, $targetTimestamp): array;

    /**
     * Transform Helioprojective Cartesian (HPC) coordinates to Heliographic Stonyhurst (HGS)
     *
     * Assumes Earth as observer and point on solar surface (1 solar radius).
     *
     * @param float $hpcX Helioprojective X coordinate in arcseconds
     * @param float $hpcY Helioprojective Y coordinate in arcseconds
     * @param string $obsTime Observation time in ISO 8601 format
     * @return array Array with 'hgs_lon' and 'hgs_lat' keys in degrees
     * @throws CoordinatorException If coordinate transformation fails
     */
    public function hpcEarthToStonyhurst(float $hpcX, float $hpcY, string $obsTime): array;

    /**
     * Batch transform HPC coordinates to Heliographic Stonyhurst
     *
     * Assumes Earth as observer and points on solar surface (1 solar radius).
     *
     * @param array $coordinateArray Array of coordinate data with 'hpc_x', 'hpc_y', 'obstime' keys
     * Example: [
     *     ['hpc_x' => 958.0, 'hpc_y' => -118.0, 'obstime' => 1013488762],
     *     ['hpc_x' => 123.0, 'hpc_y' => 456.0, 'obstime' => 1013492362]
     * ]
     * @return array Array of Stonyhurst coordinates in same order as input
     * Example: [
     *     ['hgs_lon' => 75.123, 'hgs_lat' => -7.456],
     *     ['hgs_lon' => 10.789, 'hgs_lat' => 25.012]
     * ]
     * @throws CoordinatorException If coordinate transformation fails
     */
    public function hpcEarthToStonyhurstAll(array $coordinateArray): array;
}
