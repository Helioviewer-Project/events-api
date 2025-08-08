<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Collector\Coordinate;

use Helioviewer\EventsApi\JSOC\HarpService;

/**
 * HARP-Based Coordinate Resolver
 *
 * Resolves coordinates by calling HARP service directly with HARP region ID.
 * This corresponds to ATTEMPT 1 from the original ServiceBasedResolver.
 *
 * @package Helioviewer\EventsApi\Collector\Coordinate
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class HarpResolver implements ResolverInterface
{
    public function __construct(
        private HarpService $harp
    ) {}

    /**
     * Resolve coordinates using HARP service.
     *
     * @param array $rawRecord The raw event data
     *
     * @return array|null Array with coordinate data or null if resolution fails
     */
    public function resolve(array $rawRecord): ?array
    {
        // Extract event date/time from raw record
        $eventDateTime = $this->extractEventDateTime($rawRecord);
        
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "[ATTEMPT 1] HARP SERVICE LOOKUP\n";
        echo "  Target: HARP {$rawRecord['HARPRegionId']}\n";
        echo "  Method: HarpService->getLastCoordinateForHarp()\n";
        echo "  Dataset: hmi.sharp_cea_720s\n";
        
        try {
            $harpId = (int) $rawRecord['HARPRegionId'];
            $harpData = $this->harp->getLastCoordinateForHarp($harpId, $eventDateTime);

            if ($harpData) {
                echo "  RESULT: ✓ SUCCESS\n";
                echo "  Data: " . json_encode($harpData) . "\n";
                
                // Parse NOAA ID - if multiple, return first
                $noaaId = null;
                if ($harpData['noaa_id']) {
                    $noaaIds = explode(',', $harpData['noaa_id']);
                    $noaaId = trim($noaaIds[0]);
                    // If NOAA ID is MISSING, treat as if no NOAA ID was found
                    if ($noaaId === 'MISSING') $noaaId = null;
                }
                
                $result = [
                    'latitude' => (float) $harpData['latitude'],
                    'longitude' => (float) $harpData['longitude'],
                    'region' => $noaaId ?: "HARP #{$harpId}",  // Format as "HARP #123" or NOAA ID
                    'locationTime' => $harpData['location_time'],
                    'source' => 'JSOC HARP DB'
                ];
                
                echo "  COORDINATES FOUND: lat={$result['latitude']}, lon={$result['longitude']}\n";
                echo str_repeat('=', 60) . "\n\n";
                return $result;
            } else {
                echo "  RESULT: ✗ NO DATA FOUND\n";
            }
        } catch (\Exception $e) {
            echo "  RESULT: ✗ EXCEPTION\n";
            echo "  Error: " . $e->getMessage() . "\n";
            error_log("Failed to get HARP coordinates for HARP ID {$rawRecord['HARPRegionId']}: " . $e->getMessage());
        }
        echo str_repeat('=', 60) . "\n\n";
        return null;
    }

    /**
     * Check if this resolver can process the given raw record.
     *
     * @param array $rawRecord The raw event data
     *
     * @return bool True if HARP region ID is present
     */
    public function canResolve(array $rawRecord): bool
    {
        return !empty($rawRecord['HARPRegionId']);
    }

    /**
     * Extract event date/time from raw record.
     *
     * @param array $rawRecord The raw event data
     * @return string Event date/time string
     */
    private function extractEventDateTime(array $rawRecord): string
    {
        // Try common field names in order
        $timeFields = ['start_window', 'startTime', 'beginTime', 'time', 'date'];
        
        foreach ($timeFields as $field) {
            if (isset($rawRecord[$field]) && !empty($rawRecord[$field])) {
                return date('Y-m-d H:i:s', strtotime($rawRecord[$field]));
            }
        }
        
        // Fallback to current time
        return date('Y-m-d H:i:s');
    }
}