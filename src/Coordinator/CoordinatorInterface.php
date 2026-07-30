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
     * Batch transform multiple Stonyhurst coordinates to Helioprojective Cartesian
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
    public function stonyhurstToHelioprojectiveBatch(array $coordinateArray, $targetTimestamp): array;

    /**
     * Batch transform multiple Heliographic Carrington coordinates to Helioprojective Cartesian
     *
     * @param array $coordinateArray Array of coordinate data with 'lat', 'lon', 'coordinate_time' keys
     * Example: [
     *     ['lat' => 15.0, 'lon' => 120.0, 'coordinate_time' => 1715428800],
     *     ['lat' => -10.0, 'lon' => 245.0, 'coordinate_time' => 1715432400]
     * ]
     * @param int|string $targetTimestamp Target time for coordinate rotation
     * @return array Array of rotated coordinates in same order as input
     * Example: [
     *     ['hpc_x' => 123.45, 'hpc_y' => -67.89],
     *     ['hpc_x' => -234.56, 'hpc_y' => 78.90]
     * ]
     */
    public function carringtonToHelioprojectiveBatch(array $coordinateArray, $targetTimestamp): array;

    /**
     * Batch transform HPC coordinates to HPC at a different observation time
     *
     * Transforms Helioprojective coordinates from their original observation time
     * to a target observation time, accounting for solar rotation.
     *
     * @param array $coordinateArray Array of coordinate data with 'x', 'y', 'coordinate_time' keys
     * Example: [
     *     'event-id-1' => ['x' => 100.0, 'y' => 200.0, 'coordinate_time' => 1522394520],
     *     'event-id-2' => ['x' => -150.0, 'y' => 300.0, 'coordinate_time' => 1522394520]
     * ]
     * @param int|string $targetTimestamp Target observation time
     * @return array Array of transformed coordinates with same keys as input
     * Example: [
     *     'event-id-1' => ['hpc_x' => 107.25, 'hpc_y' => 199.60],
     *     'event-id-2' => ['hpc_x' => -142.30, 'hpc_y' => 298.50]
     * ]
     * @throws CoordinatorException If coordinate transformation fails
     */
    public function helioprojectiveToHelioprojectiveBatch(array $coordinateArray, $targetTimestamp): array;
}
