<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Collector\Coordinate;

use Helioviewer\EventsApi\JSOC\NoaaService;

/**
 * NOAA from HARP Logs Coordinate Resolver
 *
 * Resolves coordinates by calling NOAA service to get coordinates from HARP logs.
 * This corresponds to ATTEMPT 2 from the original ServiceBasedResolver.
 *
 * @package Helioviewer\EventsApi\Collector\Coordinate
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class NoaaFromHarpResolver implements ResolverInterface
{
    public function __construct(
        private NoaaService $noaa
    ) {}

    /**
     * Resolve coordinates using NOAA service from HARP logs.
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
        echo "[ATTEMPT 2] NOAA SERVICE (HARP LOGS)\n";
        echo "  Target: NOAA {$rawRecord['NOAARegionId']}\n";
        echo "  Method: NoaaService->getLastCoordinateForNoaaFromHarpLogs()\n";
        echo "  Dataset: hmi.sharp_cea_720s (filtered by NOAA_ARS)\n";
        
        try {
            $noaaId = (int) $rawRecord['NOAARegionId'];
            $harpData = $this->noaa->getLastCoordinateForNoaaFromHarpLogs($noaaId, $eventDateTime);
            
            if ($harpData) {
                echo "  RESULT: ✓ SUCCESS\n";
                echo "  Data: " . json_encode($harpData) . "\n";
                
                $result = [
                    'latitude' => (float) $harpData['latitude'],
                    'longitude' => (float) $harpData['longitude'],
                    'region' => "NOAA #{$noaaId}",  // Format as "NOAA #12345"
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
            error_log("Failed to get HARP coordinates for NOAA ID {$rawRecord['NOAARegionId']}: " . $e->getMessage());
        }
        echo str_repeat('=', 60) . "\n\n";
        return null;
    }

    /**
     * Check if this resolver can process the given raw record.
     *
     * @param array $rawRecord The raw event data
     *
     * @return bool True if NOAA region ID is present
     */
    public function canResolve(array $rawRecord): bool
    {
        return !empty($rawRecord['NOAARegionId']);
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