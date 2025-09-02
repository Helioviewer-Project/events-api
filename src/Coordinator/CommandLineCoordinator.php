<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Psr\SimpleCache\CacheInterface;
use Exception;

/**
 * Command Line Coordinator Implementation
 * 
 * Implements coordinate transformation using the hgs_to_hpc command line binary
 * with caching support for performance optimization
 * 
 * @package    Helioviewer\EventsApi\Coordinator
 * @since      1.0.0
 */
class CommandLineCoordinator implements CoordinatorInterface
{
    private ?CacheInterface $cache;
    private string $binaryPath;
    
    /**
     * Constructor
     * 
     * @param CacheInterface|null $cache Optional cache interface for caching transformations
     * @param string $binaryPath Path to the hgs_to_hpc binary
     */
    public function __construct(?CacheInterface $cache = null, string $binaryPath = '/usr/local/bin/hgs_to_hpc')
    {
        $this->cache = $cache;
        $this->binaryPath = $binaryPath;
    }
    
    /**
     * Rotate Stonyhurst (HGS) coordinates to Helioprojective Cartesian (HPC) at target time
     * 
     * @param float $latitude HGS latitude
     * @param float $longitude HGS longitude
     * @param string $coordinateTime Coordinate time in ISO 8601 format
     * @param string $targetTime Target time in ISO 8601 format
     * @return array Array with 'hpc_x' and 'hpc_y' keys containing Helioprojective Cartesian coordinates
     * @throws Exception If coordinate transformation fails
     */
    public function rotate(
        float $latitude,
        float $longitude,
        string $coordinateTime,
        string $targetTime
    ): array {
        // No caching for individual coordinates, use batch method
        $result = $this->rotateAll([
            ['lat' => $latitude, 'lon' => $longitude, 'coordinate_time' => strtotime($coordinateTime)]
        ], strtotime($targetTime));
        
        return $result[0] ?? ['hpc_x' => 0, 'hpc_y' => 0];
    }
    
    /**
     * Batch rotate coordinates using simplified array format
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
    public function rotateAll(array $coordinateArray, $targetTimestamp): array
    {
        if (empty($coordinateArray)) {
            return [];
        }
        
        // Convert target timestamp to ISO format
        $parsedTimestamp = is_numeric($targetTimestamp) ? (int)$targetTimestamp : strtotime($targetTimestamp);
        $targetTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);
        
        // Create batch cache key based on signature of entire coordinate array
        if ($this->cache !== null) {
            // Create a one-liner signature using serialize and md5
            $cacheKey = 'cmdline_batch:' . md5(serialize($coordinateArray) . $parsedTimestamp);
            
            // Check if entire batch is cached
            $cachedBatch = $this->cache->get($cacheKey);
            if ($cachedBatch !== null) {
                return $cachedBatch;
            }
        }
        
        // Process all coordinates at once
        $rotatedCoordinates = $this->executeCommand($coordinateArray, $targetTime);
        
        // Cache the entire batch result (only successful transformations get here)
        if ($this->cache !== null && isset($cacheKey)) {
            $this->cache->set($cacheKey, $rotatedCoordinates, 86400); // 24 hours TTL
        }
        
        return $rotatedCoordinates;
    }
    
    /**
     * Execute the coordinate transformation command
     * 
     * @param array $coordinateArray Array of coordinates to transform
     * @param string $targetTime Target time in ISO 8601 format
     * @return array Array of transformed coordinates
     * @throws CoordinatorException If transformation fails
     */
    private function executeCommand(array $coordinateArray, string $targetTime): array
    {
        // Prepare input for the binary: multiple lines of "lat lon coord_time target_time"
        $input = '';
        foreach ($coordinateArray as $coord) {
            $coordTime = date('Y-m-d\TH:i:s\Z', $coord['coordinate_time']);
            $input .= sprintf("%f %f %s %s\n", 
                $coord['lat'], 
                $coord['lon'], 
                $coordTime, 
                $targetTime
            );
        }
        
        // Set environment variables for SunPy to use /tmp
        $env = [
            'SUNPY_CONFIGDIR' => '/tmp/sunpy_config',
            'XDG_CONFIG_HOME' => '/tmp',
            'HOME' => '/tmp'
        ];
        
        $process = proc_open($this->binaryPath, [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ], $pipes, null, $env);
        
        if (!$process) {
            throw new CoordinatorException("Failed to start coordinate transformation process");
        }
        
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        
        if ($exitCode !== 0) {
            $errorMsg = "Coordinate transformation failed";
            if ($errors) {
                $errorMsg .= ": " . trim($errors);
            }
            throw new CoordinatorException($errorMsg);
        }
        
        // Check for empty output
        if (empty(trim($output))) {
            throw new CoordinatorException("No output received from coordinate transformation");
        }
        
        // Parse output: expecting multiple lines of "hpc_x hpc_y"
        $results = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $i => $line) {
            if (empty($line)) continue;
            
            $coords = preg_split('/\s+/', trim($line));
            if (count($coords) >= 2) {
                $results[] = [
                    'hpc_x' => (float)$coords[0],
                    'hpc_y' => (float)$coords[1]
                ];
            } else {
                throw new CoordinatorException("Invalid output format for coordinate {$i}: " . $line);
            }
        }
        
        // If we got fewer results than inputs, throw exception
        if (count($results) < count($coordinateArray)) {
            throw new CoordinatorException(
                sprintf("Output mismatch: expected %d results, got %d", 
                    count($coordinateArray), 
                    count($results)
                )
            );
        }
        
        return $results;
    }
}
