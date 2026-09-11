<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Api;

use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
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
     * Builds a response containing all event types from the dictionary for the given source,
     * even if some event types have no events. This ensures a consistent API response structure.
     *
     * @param string $source The data source (e.g., 'CCMC', 'HEK', 'RHESSI') used to filter dictionary entries
     * @param array $events Array of event records (from database query)
     * @param bool $includeExtendedData Whether to include source, views, and links data
     * @return array Formatted hierarchical structure with all event types for the source
     */
    public function formatEvents(string $source, array $events, bool $includeExtendedData = true): array
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
                
                // Prepare event data (use toArray() for Eloquent models to apply casts)
                $eventArray = is_array($row) ? $row : $row->toArray();

                // Add legacy field transformations
                $eventArray['type'] = $eventArray['legacy_type'] ?? null;
                $eventArray['event_type'] = $eventArray['legacy_type'] ?? null;
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
                        $linksData = [
                            'url' => Event::getUrlById($uuid),
                            'text' => 'Helioviewer Event URL'
                        ];
                    }

                    $eventArray['link'] = $linksData;

                    // WSA views carry the event's own API urls; added here because the
                    // uuid does not exist yet when the processor builds them.
                    if (($eventArray['source_id'] ?? null) === JsonSource::WSA && isset($eventArray['views'][0]['content'])) {
                        $url = Event::getUrlById($uuid);
                        $eventArray['views'][0]['content']['EventsAPI URL'] = $url;
                        $eventArray['views'][0]['content']['EventsAPI Source URL'] = $url . '/source';
                    }

                    // Add concept for HEK events
                    if (($eventArray['source_id'] ?? null) === JsonSource::HEK) {
                        $eventArray['concept'] = $eventArray['source']['concept'];
                    }
                }

                // Add legacy id to safe guard all event system , before transition to v2 endpoints
                if (isset($eventArray['path'])) {
                    if ($eventArray['path'] === 'CCMC>>DONKI>>CME') {
                        $eventArray['legacy_id'] = $eventArray['source']['activityID'];
                    } elseif ($eventArray['path'] === 'CCMC>>DONKI>>Solar Flares') {
                        $eventArray['legacy_id'] = $eventArray['source']['flrID'];
                    } elseif(str_starts_with($eventArray['path'], 'CCMC>>Solar Flare Predictions>>') && count(explode('>>', $eventArray['path'])) === 3) {
                        $eventArray['legacy_id'] = hash('sha256', json_encode($eventArray['source']));
                    } elseif(str_starts_with($eventArray['path'], 'RHESSI>>Solar Flares>>Flare') && count(explode('>>', $eventArray['path'])) === 3) {
                        $eventArray['legacy_id'] = $eventArray['source']['id'];
                    } elseif(str_starts_with($eventArray['path'], 'HEK>>')) {
                        // HEK events use kb_archivid as legacy_id
                        $eventArray['legacy_id'] = $eventArray['source']['kb_archivid'];
                    } else {
                        $eventArray['legacy_id'] = $eventArray['id'];
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
                        // For HEK events, use frm_contact and frm_url from source
                        if (str_starts_with($path, 'HEK>>')) {
                            $processedResult[$parentPath]['groups'][$path] = [
                                'name' => $parts[2],
                                'contact' => $eventArray['source']['frm_contact'],
                                'url' => $eventArray['source']['frm_url'],
                                'data' => []
                            ];
                        } else {
                            $processedResult[$parentPath]['groups'][$path] = [
                                'name' => $parts[2],
                                'contact' => $path,
                                'url' => $path,
                                'data' => []
                            ];
                        }
                    }
                }
                
                // Add event to data array
                $processedResult[$parentPath]['groups'][$path]['data'][] = $eventArray;
            }
            // Ignore paths with more than 3 levels
        }

        ksort($processedResult);

        // Also convert groups from associative to indexed arrays
        foreach ($processedResult as &$item) {
            if (isset($item['groups']) && is_array($item['groups'])) {
                $item['groups'] = array_values($item['groups']);
            }
        }

        // Build final result using dictionary order, ensuring all event types for the source are included.
        // This guarantees a consistent API response structure even when some event types have no events.
        $finalResult = [];

        foreach ($this->dictionary as $dictPath => $dictValue) {
            if (isset($processedResult[$dictPath])) {
                // Event type has data - use the processed result
                $finalResult[] = $processedResult[$dictPath];
            } else {
                // Event type has no data - include it with empty groups if it belongs to the requested source
                $pathParts = explode('>>', $dictPath);
                $isTopLevelForSource = count($pathParts) === 2 && str_starts_with($dictPath, $source . '>>');

                if ($isTopLevelForSource) {
                    $dictValue['groups'] = [];
                    $finalResult[] = $dictValue;
                }
            }
        }

        return $finalResult;
    }
    
    /**
     * Format events for batch observations endpoint.
     *
     * Returns event_types hierarchy (with event_ids instead of data) and
     * a static events dict keyed by ID. No source/views/links loaded.
     *
     * @param string $source Source name for dictionary filtering
     * @param array $events Array of event records
     * @return array ['event_types' => [...], 'events' => [...]]
     */
    public function formatEventsBatched(string $source, array $events): array
    {
        $processedResult = [];
        $eventsDict = [];

        foreach ($events as $row) {
            $path = is_array($row) ? ($row['path'] ?? null) : ($row->path ?? null);
            if (!$path) continue;

            $parts = explode('>>', $path);
            $eventArray = is_array($row) ? $row : $row->toArray();
            $eventId = $eventArray['id'];

            // Build static event entry
            if (!isset($eventsDict[$eventId])) {
                $eventsDict[$eventId] = [
                    'label' => $eventArray['label'] ?? null,
                    'short_label' => $eventArray['short_label'] ?? null,
                    'start' => isset($eventArray['start']) ? date('Y-m-d\TH:i:s', $eventArray['start']) : null,
                    'end' => isset($eventArray['end']) ? date('Y-m-d\TH:i:s', $eventArray['end']) : null,
                    'peak' => isset($eventArray['peak']) ? date('Y-m-d\TH:i:s', $eventArray['peak']) : null,
                    'hv_hpc_x' => $eventArray['hv_hpc_x'] ?? null,
                    'hv_hpc_y' => $eventArray['hv_hpc_y'] ?? null,
                    'footprint' => $eventArray['footprint'] ?? [],
                    'coordinate_system' => $eventArray['coordinate_system'] ?? null,
                    'coordinate_time' => $eventArray['coordinate_time'] ?? null,
                    'type' => $eventArray['legacy_type'] ?? null,
                    'version' => $eventArray['legacy_version'] ?? null,
                    'pin' => $eventArray['legacy_pin'] ?? null,
                    'concept' => $eventArray['concept'] ?? null,
                    'legacy_id' => $eventArray['legacy_id'] ?? $eventId,
                ];
            }

            if (count($parts) <= 2) {
                if (!isset($processedResult[$path])) {
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
            } elseif (count($parts) == 3) {
                $parentPath = $parts[0] . '>>' . $parts[1];

                if (!isset($processedResult[$parentPath])) {
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

                if (!isset($processedResult[$parentPath]['groups'][$path])) {
                    if (isset($this->dictionary[$path])) {
                        $processedResult[$parentPath]['groups'][$path] = [
                            'name' => $this->dictionary[$path]['name'] ?: $parts[2],
                            'contact' => $this->dictionary[$path]['contact'] ?? '',
                            'url' => $this->dictionary[$path]['url'] ?? '',
                            'event_ids' => []
                        ];
                    } else {
                        $processedResult[$parentPath]['groups'][$path] = [
                            'name' => $parts[2],
                            'contact' => '',
                            'url' => '',
                            'event_ids' => []
                        ];
                    }
                }

                $processedResult[$parentPath]['groups'][$path]['event_ids'][] = $eventId;
            }
        }

        ksort($processedResult);

        foreach ($processedResult as &$item) {
            if (isset($item['groups']) && is_array($item['groups'])) {
                $item['groups'] = array_values($item['groups']);
            }
        }

        // Build final result using dictionary order
        $eventTypes = [];
        foreach ($this->dictionary as $dictPath => $dictValue) {
            if (isset($processedResult[$dictPath])) {
                $eventTypes[] = $processedResult[$dictPath];
            } else {
                $pathParts = explode('>>', $dictPath);
                $isTopLevelForSource = count($pathParts) === 2 && str_starts_with($dictPath, $source . '>>');
                if ($isTopLevelForSource) {
                    $dictValue['groups'] = [];
                    $eventTypes[] = $dictValue;
                }
            }
        }

        return [
            'event_types' => $eventTypes,
            'events' => $eventsDict,
        ];
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
