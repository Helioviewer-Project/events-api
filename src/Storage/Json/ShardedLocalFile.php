<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Storage\Json;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * Sharded Local File Storage
 * 
 * Implements 5-level deep directory sharding to prevent filesystem
 * performance degradation with large numbers of files.
 * 
 * For UUID: a53f4b7c-1234-5678-9012-123456789012
 * Creates: /base/type/a5/3f/4b/7c/12/uuid.json
 * 
 * @package Helioviewer\EventsApi\Storage\Json
 */
class ShardedLocalFile implements JsonStorageInterface
{
    private Filesystem $filesystem;
    private string $basePath;
    
    /**
     * Constructor
     * 
     * @param string $basePath Base path for storage
     */
    public function __construct(string $basePath = '/u/apps/data')
    {
        $this->filesystem = new Filesystem();
        $this->basePath = rtrim($basePath, '/');
    }
    
    /**
     * Get 5-level sharded path for a given ID
     * 
     * @param string $id UUID or identifier
     * @param string $type Type of data (sources, views, links, etc.)
     * @return string Full path with 5-level sharding
     */
    private function getShardedPath(string $id, string $type): string
    {
        // Remove hyphens from UUID for consistent sharding
        $cleanId = str_replace('-', '', $id);
        
        // Extract 5 levels (2 chars each = 10 chars total)
        $level1 = substr($cleanId, 0, 2);
        $level2 = substr($cleanId, 2, 2);
        $level3 = substr($cleanId, 4, 2);
        $level4 = substr($cleanId, 6, 2);
        $level5 = substr($cleanId, 8, 2);
        
        // Build path: /base/type/l1/l2/l3/l4/l5/uuid.json
        return sprintf(
            '%s/%s/%s/%s/%s/%s/%s/%s.json',
            $this->basePath,
            $type,
            $level1,
            $level2,
            $level3,
            $level4,
            $level5,
            $id
        );
    }
    
    /**
     * Store JSON data to file with 5-level sharding
     * 
     * @param string $path Path format: /type/uuid
     * @param array $data JSON data
     */
    public function store(string $path, array $data): void
    {
        // Parse the path to extract type and ID
        $parts = $this->parsePath($path);
        $fullPath = $this->getShardedPath($parts['id'], $parts['type']);
        
        try {
            // Atomic write with automatic directory creation
            $this->filesystem->dumpFile(
                $fullPath,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } catch (IOExceptionInterface $exception) {
            throw new \RuntimeException(
                "Failed to write file at {$exception->getPath()}: {$exception->getMessage()}"
            );
        }
    }
    
    /**
     * Load JSON data from sharded file structure
     * 
     * @param string $path Path format: /type/uuid
     * @return array|null JSON data or null if not found
     */
    public function load(string $path): ?array
    {
        // Parse the path to extract type and ID
        $parts = $this->parsePath($path);
        $fullPath = $this->getShardedPath($parts['id'], $parts['type']);
        
        if (!$this->filesystem->exists($fullPath)) {
            return null;
        }
        
        $content = file_get_contents($fullPath);
        if ($content === false) {
            return null;
        }
        
        return json_decode($content, true);
    }
    
    /**
     * Parse path to extract type and ID
     * 
     * @param string $path Path like /u/apps/data/sources/uuid.json
     * @return array{type: string, id: string}
     */
    private function parsePath(string $path): array
    {
        // Remove .json extension if present
        $path = preg_replace('/\.json$/i', '', $path);
        
        // Extract the last two parts (type/id)
        $parts = explode('/', trim($path, '/'));
        $id = array_pop($parts);
        $type = array_pop($parts);
        
        // If type is missing, use the first directory after base path
        if (empty($type)) {
            $type = 'data';
        }
        
        return [
            'type' => $type,
            'id' => $id
        ];
    }
    
    /**
     * Delete file from sharded structure
     * 
     * @param string $path Path format: /type/uuid
     */
    public function delete(string $path): void
    {
        $parts = $this->parsePath($path);
        $fullPath = $this->getShardedPath($parts['id'], $parts['type']);
        
        if ($this->filesystem->exists($fullPath)) {
            $this->filesystem->remove($fullPath);
            
            // Clean up empty directories
            $this->cleanEmptyDirectories(dirname($fullPath));
        }
    }
    
    /**
     * Clean up empty directories going up the tree
     * 
     * @param string $dir Directory to start cleaning from
     */
    private function cleanEmptyDirectories(string $dir): void
    {
        $levels = 5; // Only go up 5 levels
        while ($levels > 0 && $dir !== $this->basePath) {
            if (is_dir($dir)) {
                $files = scandir($dir);
                if ($files !== false && count($files) === 2) { // Only . and ..
                    rmdir($dir);
                    $dir = dirname($dir);
                    $levels--;
                } else {
                    break;
                }
            } else {
                break;
            }
        }
    }
    
    /**
     * Check if file exists in sharded structure
     * 
     * @param string $path Path format: /type/uuid
     * @return bool
     */
    public function exists(string $path): bool
    {
        $parts = $this->parsePath($path);
        $fullPath = $this->getShardedPath($parts['id'], $parts['type']);
        
        return $this->filesystem->exists($fullPath);
    }
    
    /**
     * Store with explicit type and ID (alternative interface)
     * 
     * @param string $id UUID or identifier  
     * @param string $type Type of data (sources, views, links)
     * @param array $data JSON data
     * @return string The full path where the file was stored
     */
    public function storeById(string $id, string $type, array $data): string
    {
        $fullPath = $this->getShardedPath($id, $type);
        
        try {
            $this->filesystem->dumpFile(
                $fullPath,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } catch (IOExceptionInterface $exception) {
            throw new \RuntimeException(
                "Failed to write file at {$exception->getPath()}: {$exception->getMessage()}"
            );
        }
        
        return $fullPath;
    }
    
    /**
     * Load with explicit type and ID (alternative interface)
     * 
     * @param string $id UUID or identifier
     * @param string $type Type of data
     * @return array|null
     */
    public function loadById(string $id, string $type): ?array
    {
        $fullPath = $this->getShardedPath($id, $type);
        
        if (!$this->filesystem->exists($fullPath)) {
            return null;
        }
        
        $content = file_get_contents($fullPath);
        if ($content === false) {
            return null;
        }
        
        return json_decode($content, true);
    }
}