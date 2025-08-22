<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Storage\Json;

/**
 * JSON Storage Interface
 *
 * Simple contract for storing and retrieving JSON data files.
 *
 * @package Helioviewer\EventsApi\Storage\Json
 */
interface JsonStorageInterface
{
    /**
     * Store JSON data to file.
     *
     * @param string $path File path to store data
     * @param array $data JSON data
     *
     * @return void
     */
    public function store(string $path, array $data): void;

    /**
     * Load JSON data from file.
     *
     * @param string $path File path to load data from
     *
     * @return array|null JSON data or null if not found
     */
    public function load(string $path): ?array;
}