<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Storage\Json;

/**
 * Local File Storage
 *
 * Stores JSON data to local files.
 *
 * @package Helioviewer\EventsApi\Storage\Json
 */
class LocalFile implements JsonStorageInterface
{
    /**
     * Store JSON data to file.
     *
     * @param string $path File path
     * @param array $data JSON data
     */
    public function store(string $path, array $data): void
    {
        // Create directory if needed
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Store JSON data
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Load JSON data from file.
     *
     * @param string $path File path
     *
     * @return array|null JSON data or null if not found
     */
    public function load(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return json_decode($content, true);
    }

    /**
     * Store with explicit type and ID (alternative interface)
     * 
     * @param string $id File identifier  
     * @param string $type Type of data (directory path)
     * @param mixed $data JSON data (array or object)
     * @return string The full path where the file was stored
     */
    public function storeById(string $id, string $type, mixed $data): string
    {
        $basePath = '/u/apps/data';
        $fullPath = $basePath . '/' . $type . '/' . $id . '.json';
        
        // Create directory if needed
        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Store JSON data
        file_put_contents($fullPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $fullPath;
    }

    /**
     * Delete a stored file. Missing files are not an error.
     *
     * @param string $path File path
     */
    public function delete(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}