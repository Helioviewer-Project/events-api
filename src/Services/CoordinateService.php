<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Services;

use HelioviewerEventInterface\Coordinator\Coordinator;

/**
 * Coordinate Transformation Service
 *
 * Provides coordinate system transformations for solar events, primarily
 * handling conversions between different heliocentric coordinate systems
 * and temporal adjustments for solar rotation. Uses the Helioviewer
 * Coordinator API for accurate astronomical calculations.
 *
 * Supported transformations:
 * - HGS (Heliographic Stonyhurst) to HPC (Helioprojective Cartesian)
 * - Time-based coordinate rotation for solar rotation compensation
 * - Fallback handling for transformation failures
 *
 * @package Helioviewer\EventsApi\Services
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class CoordinateService
{
    /**
     * Transform HGS coordinates to account for solar rotation over time.
     *
     * Converts Heliographic Stonyhurst coordinates from an event time
     * to what they would appear as at a different observation time,
     * accounting for solar rotation. This is essential for accurate
     * event positioning when viewing data at different times.
     *
     * The transformation process:
     * 1. Converts input timestamps to ISO 8601 format
     * 2. Uses Coordinator API to perform HGS to HPC transformation
     * 3. Maps HPC coordinates back to latitude/longitude format
     * 4. Provides fallback to original coordinates if transformation fails
     *
     * @param float $latitude Event latitude in HGS coordinates (degrees)
     * @param float $longitude Event longitude in HGS coordinates (degrees)
     * @param int $eventTime Original event time (Unix timestamp)
     * @param int $observationTime Target observation time (Unix timestamp)
     *
     * @return array{latitude: float, longitude: float} Transformed coordinates
     *   - latitude: Rotated latitude (degrees)
     *   - longitude: Rotated longitude (degrees)
     */
    public function rotateToTime(
        float $latitude,
        float $longitude,
        int $eventTime,
        int $observationTime
    ): array {
        // Convert Unix timestamps to ISO 8601 format required by Coordinator API
        $eventTimeStr = date('Y-m-d\TH:i:s\Z', $eventTime);
        $observationTimeStr = date('Y-m-d\TH:i:s\Z', $observationTime);
        
        try {
            // Perform HGS to HPC coordinate transformation using Coordinator API
            // This accounts for solar rotation between event time and observation time
            $rotatedCoords = Coordinator::Hgs2Hpc(
                $latitude,
                $longitude,
                $eventTimeStr,
                $observationTimeStr
            );
            
            // Map HPC coordinates back to latitude/longitude format
            // Note: HPC X corresponds to solar latitude, HPC Y to solar longitude
            return [
                'latitude' => $rotatedCoords['x'],  // HPC X becomes latitude
                'longitude' => $rotatedCoords['y']  // HPC Y becomes longitude
            ];
            
        } catch (\Exception $e) {
            // Log transformation errors for debugging
            error_log("Coordinate transformation failed: " . $e->getMessage());
            
            // Graceful fallback: return original coordinates if transformation fails
            // This ensures the system remains functional even with coordinate issues
            return [
                'latitude' => $latitude,
                'longitude' => $longitude
            ];
        }
    }
}