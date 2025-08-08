<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Collector\Coordinate;

use Helioviewer\EventsApi\JSOC\NoaaService;

/**
 * Direct NOAA Active Region Coordinate Resolver
 *
 * Resolves coordinates by calling NOAA service directly for NOAA active regions.
 * This corresponds to ATTEMPT 3 from the original ServiceBasedResolver.
 *
 * @package Helioviewer\EventsApi\Collector\Coordinate
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class DirectNoaaResolver implements ResolverInterface
{
    public function __construct(
        private NoaaService $noaa
    ) {}

    /**
     * Resolve coordinates using direct NOAA service call.
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
        echo "[ATTEMPT 3] NOAA SERVICE (DIRECT)\n";
        echo "  Target: NOAA {$rawRecord['NOAARegionId']}\n";
        echo "  Method: NoaaService->getLastCoordinateForNoaa()\n";
        echo "  Dataset: jsoc.noaa_active_regions\n";
        echo "  Request URL: http://jsoc.stanford.edu/cgi-bin/ajax/jsoc_info?ds=jsoc.noaa_active_regions[][{$rawRecord['NOAARegionId']}]&op=rs_list&key=RegionNumber,ObservationTime,LatitudeHG,LongitudeHG,LongitudeCM\n";
        
        try {
            $noaaId = (int) $rawRecord['NOAARegionId'];
            $noaaData = $this->noaa->getLastCoordinateForNoaa($noaaId, $eventDateTime);
            
            if ($noaaData) {
                echo "  RESULT: ✓ SUCCESS\n";
                echo "  Data: " . json_encode($noaaData) . "\n";
                
                $result = [
                    'latitude' => (float) $noaaData['latitude'],
                    'longitude' => (float) $noaaData['longitude'],
                    'region' => "NOAA #{$noaaId}",  // Format as "NOAA #12345"
                    'locationTime' => $noaaData['location_time'],
                    'source' => 'JSOC NOAA DB'
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
            error_log("Failed to get NOAA coordinates from NoaaService for NOAA ID {$rawRecord['NOAARegionId']}: " . $e->getMessage());
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