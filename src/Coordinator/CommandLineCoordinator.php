<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Psr\SimpleCache\CacheInterface;
use Exception;
use BadMethodCallException;

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
    private array $binaryPaths;

    /**
     * Constructor
     *
     * @param CacheInterface|null $cache Optional cache interface for caching transformations
     * @param array $binaryPaths Optional array of binary paths, keyed by transformation name
     */
    public function __construct(?CacheInterface $cache = null, array $binaryPaths = [])
    {
        $this->cache = $cache;
        $this->binaryPaths = array_merge([
            'hgs_to_hpc' => '/usr/local/bin/hgs_to_hpc',
            'hpc_earth_to_stonyhurst' => '/usr/local/bin/hpc_earth_to_stonyhurst',
        ], $binaryPaths);
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
    public function stonyhurstToHelioprojective(
        float $latitude,
        float $longitude,
        string $coordinateTime,
        string $targetTime
    ): array {
        // No caching for individual coordinates, use batch method
        $result = $this->stonyhurstToHelioprojectiveBatch([
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
    public function stonyhurstToHelioprojectiveBatch(array $coordinateArray, $targetTimestamp): array
    {
        if (empty($coordinateArray)) {
            return [];
        }

        // Convert target timestamp to ISO format
        $parsedTimestamp = is_numeric($targetTimestamp) ? (int)$targetTimestamp : strtotime($targetTimestamp);
        $targetTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);

        // Create batch cache key based on signature of entire coordinate array
        if ($this->cache !== null) {
            $cacheKey = 'cmdline_batch:' . md5(serialize($coordinateArray) . $parsedTimestamp);

            $cachedBatch = $this->cache->get($cacheKey);
            if ($cachedBatch !== null) {
                return $cachedBatch;
            }
        }

        // Track original keys for result mapping
        $originalKeys = array_keys($coordinateArray);

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

        $process = proc_open($this->binaryPaths['hgs_to_hpc'], [
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

        if (empty(trim($output))) {
            throw new CoordinatorException("No output received from coordinate transformation");
        }

        // Parse output: expecting multiple lines of "hpc_x hpc_y"
        $rotatedCoordinates = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $i => $line) {
            if (empty($line)) continue;

            $coords = preg_split('/\s+/', trim($line));
            if (count($coords) >= 2) {
                $originalKey = $originalKeys[$i];
                $rotatedCoordinates[$originalKey] = [
                    'hpc_x' => (float)$coords[0],
                    'hpc_y' => (float)$coords[1]
                ];
            } else {
                throw new CoordinatorException("Invalid output format for coordinate {$i}: " . $line);
            }
        }

        if (count($rotatedCoordinates) < count($coordinateArray)) {
            throw new CoordinatorException(
                sprintf("Output mismatch: expected %d results, got %d",
                    count($coordinateArray),
                    count($rotatedCoordinates)
                )
            );
        }

        // Cache the entire batch result
        if ($this->cache !== null && isset($cacheKey)) {
            $this->cache->set($cacheKey, $rotatedCoordinates, 86400); // 24 hours TTL
        }

        return $rotatedCoordinates;
    }

    /**
     * Transform HPC coordinates (Earth observer) to Heliographic Stonyhurst
     *
     * Assumes point is on the solar surface (1 solar radius from Sun center).
     *
     * @param float $hpcX Helioprojective X in arcseconds
     * @param float $hpcY Helioprojective Y in arcseconds
     * @param string $obsTime Observation time in ISO 8601 format
     * @return array Array with 'hgs_lon' and 'hgs_lat' keys in degrees
     * @throws CoordinatorException If transformation fails
     */
    public function helioprojectiveFromEarthToStonyhurst(float $hpcX, float $hpcY, string $obsTime): array
    {
        $result = $this->helioprojectiveFromEarthToStonyhurstBatch([
            ['hpc_x' => $hpcX, 'hpc_y' => $hpcY, 'obstime' => $obsTime]
        ]);

        return $result[0];
    }

    /**
     * Batch transform HPC coordinates (Earth observer) to Heliographic Stonyhurst
     *
     * @param array $coordinateArray Array with 'hpc_x', 'hpc_y', 'obstime' keys
     * @return array Array of ['hgs_lon', 'hgs_lat'] in same order as input
     * @throws CoordinatorException If transformation fails
     */
    public function helioprojectiveFromEarthToStonyhurstBatch(array $coordinateArray): array
    {
        if (empty($coordinateArray)) {
            return [];
        }

        // Track original keys for result mapping
        $originalKeys = array_keys($coordinateArray);

        // Prepare input: multiple lines of "hpc_x hpc_y obstime"
        $input = '';
        foreach ($coordinateArray as $coord) {
            $obsTime = is_numeric($coord['obstime'])
                ? date('Y-m-d\TH:i:s\Z', (int)$coord['obstime'])
                : $coord['obstime'];
            $input .= sprintf("%f %f %s\n", $coord['hpc_x'], $coord['hpc_y'], $obsTime);
        }

        // Set environment variables for SunPy
        $env = [
            'SUNPY_CONFIGDIR' => '/tmp/sunpy_config',
            'XDG_CONFIG_HOME' => '/tmp',
            'HOME' => '/tmp'
        ];

        $process = proc_open($this->binaryPaths['hpc_earth_to_stonyhurst'], [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ], $pipes, null, $env);

        if (!$process) {
            throw new CoordinatorException("Failed to start batch HPC to Stonyhurst transformation process");
        }

        fwrite($pipes[0], $input);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $errorMsg = "Batch HPC to Stonyhurst transformation failed";
            if ($errors) {
                $errorMsg .= ": " . trim($errors);
            }
            throw new CoordinatorException($errorMsg);
        }

        if (empty(trim($output))) {
            throw new CoordinatorException("No output received from batch HPC to Stonyhurst transformation");
        }

        // Parse output: multiple lines of "hgs_lon hgs_lat"
        $results = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $i => $line) {
            if (empty($line)) continue;

            $coords = preg_split('/\s+/', trim($line));
            if (count($coords) >= 2) {
                $originalKey = $originalKeys[$i];
                $results[$originalKey] = [
                    'hgs_lon' => (float)$coords[0],
                    'hgs_lat' => (float)$coords[1]
                ];
            } else {
                throw new CoordinatorException("Invalid output format for coordinate {$i}: " . $line);
            }
        }

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

    /**
     * Batch transform HPC coordinates to HPC at a different observation time
     *
     * @param array $coordinateArray Array of coordinates with 'x', 'y', 'coordinate_time' keys
     * @param int|string $targetTimestamp Target observation time
     * @return array Array of transformed coordinates with same keys as input
     * @throws BadMethodCallException Always thrown - not implemented
     */
    public function helioprojectiveToHelioprojectiveBatch(array $coordinateArray, $targetTimestamp): array
    {
        throw new BadMethodCallException('HPC to HPC batch transformation is not implemented in CommandLineCoordinator');
    }
}
