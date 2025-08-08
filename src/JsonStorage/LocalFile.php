<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\JsonStorage;

/**
 * Local File Storage
 *
 * Stores JSON data to local files.
 *
 * @package Helioviewer\EventsApi\JsonStorage
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
}