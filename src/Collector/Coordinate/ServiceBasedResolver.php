<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Collector\Coordinate;

use Helioviewer\EventsApi\JSOC\HarpService;
use Helioviewer\EventsApi\JSOC\NoaaService;

/**
 * Service-Based Coordinate Resolver
 *
 * Resolves coordinates by calling external services (HARP and NOAA).
 * Uses a multi-step approach to find coordinates through different service endpoints.
 *
 * @package Helioviewer\EventsApi\Collector\Coordinate
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class ServiceBasedResolver implements ResolverInterface
{
    public function __construct(
        private HarpService $harp,
        private NoaaService $noaa
    ) {}

    /**
     * Resolve coordinates using external services.
     *
     * @param array $rawRecord The raw event data
     *
     * @return array|null Array with coordinate data or null if resolution fails
     */
    public function resolve(array $rawRecord): ?array
    {
        // Extract event date/time from raw record for service calls
        $eventDateTime = $this->extractEventDateTime($rawRecord);
        
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "COORDINATE RESOLUTION PROCESS\n";
        echo str_repeat('=', 60) . "\n";
        
        echo "Event Time: {$eventDateTime}\n";
        echo "Available IDs: ";
        $ids = [];
        if (isset($rawRecord['HARPRegionId']) && !empty($rawRecord['HARPRegionId'])) {
            $ids[] = "HARP={$rawRecord['HARPRegionId']}";
        }
        if (isset($rawRecord['NOAARegionId']) && !empty($rawRecord['NOAARegionId'])) {
            $ids[] = "NOAA={$rawRecord['NOAARegionId']}";
        }
        echo (!empty($ids) ? implode(', ', $ids) : "NONE") . "\n";
        echo str_repeat('-', 60) . "\n\n";

        // ATTEMPT 1: HARP ID LOOKUP
        if (isset($rawRecord['HARPRegionId']) && !empty($rawRecord['HARPRegionId'])) {
            $result = $this->tryHarpLookup($rawRecord, $eventDateTime);
            if ($result !== null) {
                echo str_repeat('=', 60) . "\n\n";
                return $result;
            }
        }

        // ATTEMPT 2: NOAA ID via HARP LOGS
        if (isset($rawRecord['NOAARegionId']) && !empty($rawRecord['NOAARegionId'])) {
            $result = $this->tryNoaaFromHarpLogs($rawRecord, $eventDateTime);
            if ($result !== null) {
                echo str_repeat('=', 60) . "\n\n";
                return $result;
            }
        }

        // ATTEMPT 3: Direct NOAA Active Region lookup
        if (isset($rawRecord['NOAARegionId']) && !empty($rawRecord['NOAARegionId'])) {
            $result = $this->tryDirectNoaaLookup($rawRecord, $eventDateTime);
            if ($result !== null) {
                echo str_repeat('=', 60) . "\n\n";
                return $result;
            }
        }
        
        echo "[FINAL RESULT] ALL ATTEMPTS FAILED\n";
        echo "  No coordinates could be resolved\n";
        echo str_repeat('=', 60) . "\n\n";
        return null;
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

    /**
     * Check if this resolver can process the given raw record.
     *
     * @param array $rawRecord The raw event data
     *
     * @return bool True if HARP or NOAA region IDs are present
     */
    public function canResolve(array $rawRecord): bool
    {
        return !empty($rawRecord['HARPRegionId']) || !empty($rawRecord['NOAARegionId']);
    }

    private function tryHarpLookup(array $rawRecord, string $eventDateTime): ?array
    {
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
                return $result;
            } else {
                echo "  RESULT: ✗ NO DATA FOUND\n";
            }
        } catch (\Exception $e) {
            echo "  RESULT: ✗ EXCEPTION\n";
            echo "  Error: " . $e->getMessage() . "\n";
            error_log("Failed to get HARP coordinates for HARP ID {$rawRecord['HARPRegionId']}: " . $e->getMessage());
        }
        echo "\n";
        return null;
    }

    private function tryNoaaFromHarpLogs(array $rawRecord, string $eventDateTime): ?array
    {
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
                return $result;
            } else {
                echo "  RESULT: ✗ NO DATA FOUND\n";
            }
        } catch (\Exception $e) {
            echo "  RESULT: ✗ EXCEPTION\n";
            echo "  Error: " . $e->getMessage() . "\n";
            error_log("Failed to get HARP coordinates for NOAA ID {$rawRecord['NOAARegionId']}: " . $e->getMessage());
        }
        echo "\n";
        return null;
    }

    private function tryDirectNoaaLookup(array $rawRecord, string $eventDateTime): ?array
    {
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
                return $result;
            } else {
                echo "  RESULT: ✗ NO DATA FOUND\n";
            }
        } catch (\Exception $e) {
            echo "  RESULT: ✗ EXCEPTION\n";
            echo "  Error: " . $e->getMessage() . "\n";
            error_log("Failed to get NOAA coordinates from NoaaService for NOAA ID {$rawRecord['NOAARegionId']}: " . $e->getMessage());
        }
        echo "\n";
        return null;
    }
}
