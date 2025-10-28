<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Api;

use Helioviewer\EventsApi\Storage\Json\LocalFile;
use Helioviewer\EventsApi\Storage\Json\JsonStorageInterface;

/**
 * Legacy Event Response Formatter
 * 
 * Formats events into hierarchical structure based on paths
 * with support for 2-level and 3-level path grouping
 * 
 * @package    Helioviewer\EventsApi\Response
 * @author     Based on test.php logic
 * @since      1.0.0
 */
class Legacy
{
    private array $dictionary;
    private JsonStorageInterface $storage;
    
    /**
     * Constructor
     * 
     * @param JsonStorageInterface $json_storage Storage service for loading JSON files
     */
    public function __construct(JsonStorageInterface $json_storage)
    {
        // Load the dictionary from the configuration file
        $this->dictionary = require __DIR__ . '/event_paths_dictionary.php';
        
        $this->storage = $json_storage;
    }
    
    /**
     * Format events into hierarchical structure based on paths
     * 
     * @param array $events Array of event records (from database query)
     * @param bool $includeExtendedData Whether to include source, views, and links data
     * @return array Formatted hierarchical structure
     */
    public function formatEvents(array $events, bool $includeExtendedData = true): array
    {
        $processedResult = [];
        
        foreach ($events as $row) {
            // Handle both objects and arrays
            $path = is_array($row) ? ($row['path'] ?? null) : ($row->path ?? null);
            
            // Skip if no path
            if (!$path) continue;
            
            // Split path by >> delimiter
            $parts = explode('>>', $path);
            
            if (count($parts) <= 2) {
                // 1 or 2 level path - these are main branches
                if (!isset($processedResult[$path])) {
                    // Use dictionary values if available, otherwise use defaults
                    if (isset($this->dictionary[$path])) {
                        $processedResult[$path] = [
                            'name' => $this->dictionary[$path]['name'] ?: $path,
                            'pin' => $this->dictionary[$path]['pin'] ?: $path,
                            'groups' => []
                        ];
                    } else {
                        $processedResult[$path] = [
                            'name' => $path,
                            'pin' => $path,
                            'groups' => []
                        ];
                    }
                }
            } else if (count($parts) == 3) {
                // 3 level path - these go into parent's groups
                // Parent is the first 2 levels
                $parentPath = $parts[0] . '>>' . $parts[1];
                
                // Ensure parent exists
                if (!isset($processedResult[$parentPath])) {
                    // Use dictionary values for parent if available
                    if (isset($this->dictionary[$parentPath])) {
                        $processedResult[$parentPath] = [
                            'name' => $this->dictionary[$parentPath]['name'] ?: $parentPath,
                            'pin' => $this->dictionary[$parentPath]['pin'] ?: $parentPath,
                            'groups' => []
                        ];
                    } else {
                        $processedResult[$parentPath] = [
                            'name' => $parentPath,
                            'pin' => $parentPath,
                            'groups' => []
                        ];
                    }
                }
                
                // Add this 3-level path to parent's groups
                if (!isset($processedResult[$parentPath]['groups'][$path])) {
                    // Use dictionary values if available
                    if (isset($this->dictionary[$path])) {
                        $processedResult[$parentPath]['groups'][$path] = [
                            'name' => $this->dictionary[$path]['name'] ?: $parts[2],
                            'contact' => $this->dictionary[$path]['contact'] ?: $path,
                            'url' => $this->dictionary[$path]['url'] ?: $path,
                            'data' => []
                        ];
                    } else {
                        $processedResult[$parentPath]['groups'][$path] = [
                            'name' => $parts[2], // Just the last part
                            'contact' => $path,
                            'url' => $path,
                            'data' => []
                        ];
                    }
                }
                
                // Prepare event data
                $eventArray = is_array($row) ? $row : (array) $row;
                
                // Add legacy field transformations
                $eventArray['type'] = $eventArray['legacy_type'] ?? null;
                $eventArray['version'] = $eventArray['legacy_version'] ?? null;
                $eventArray['pin'] = $eventArray['legacy_pin'] ?? null;
                
                // Format timestamps to ISO 8601 with T separator
                if (isset($eventArray['start'])) {
                    $eventArray['start'] = date('Y-m-d\TH:i:s', $eventArray['start']);
                }
                if (isset($eventArray['end'])) {
                    $eventArray['end'] = date('Y-m-d\TH:i:s', $eventArray['end']);
                }
                if (isset($eventArray['peak'])) {
                    $eventArray['peak'] = date('Y-m-d\TH:i:s', $eventArray['peak']);
                }
                
                // Add extended data if requested
                if ($includeExtendedData) {
                    $uuid = is_array($row) ? $row['id'] : $row->id;
                    
                    // Load source JSON data using sharded storage
                    $sourceData = $this->storage->loadById($uuid, 'sources');
                    $eventArray['source'] = $sourceData ?: null;
                    
                    // Load views JSON data using sharded storage
                    $viewsData = $this->storage->loadById($uuid, 'views');
                    $eventArray['views'] = $viewsData ?: [];
                    
                    // Load links JSON data using sharded storage
                    $linksData = $this->storage->loadById($uuid, 'links');
                    
                    // If no link exists, create a default link to the event URL
                    if (empty($linksData)) {
                        $apiUrl = rtrim($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', '/');
                        $linksData = [
                            'url' => "{$apiUrl}/api/v2/events/{$uuid}",
                            'text' => 'Helioviewer Event URL'
                        ];
                    }
                    
                    $eventArray['link'] = $linksData;
                }
                
                // Add event to data array
                $processedResult[$parentPath]['groups'][$path]['data'][] = $eventArray;
            }
            // Ignore paths with more than 3 levels
        }

        ksort($processedResult);
        
        // Convert associative arrays to indexed arrays
        $finalResult = array_values($processedResult);

        // Also convert groups from associative to indexed arrays
        foreach ($finalResult as &$item) {
            if (isset($item['groups']) && is_array($item['groups'])) {
                $item['groups'] = array_values($item['groups']);
            }
        }

        return $finalResult;
    }
    
    /**
     * Update the dictionary with new values
     * 
     * @param string $path The path key
     * @param array $values The values to set for this path
     */
    public function updateDictionary(string $path, array $values): void
    {
        $this->dictionary[$path] = $values;
    }
    
    /**
     * Get the current dictionary
     * 
     * @return array
     */
    public function getDictionary(): array
    {
        return $this->dictionary;
    }
}
