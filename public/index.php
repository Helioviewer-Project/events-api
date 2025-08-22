<?php

$container = require __DIR__ . '/../src/container.php';

// Get services from container
$coordinator = $container['coordinator'];
$jsonStorage = $container['jsonStorage'];
$eventRepository = $container['eventRepository'];
$regionRepository = $container['regionRepository'];

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Helioviewer\EventsApi\Events\Event;
use HelioviewerEventInterface\Coordinator\Coordinator;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Utils\TimestampParser;
use Helioviewer\EventsApi\Api\Legacy as LegacyEventResponse;

// Create Slim app
$app = AppFactory::create();

// Use repository from container

// Add routing middleware
$app->addRoutingMiddleware();

// Add error handling
$app->addErrorMiddleware(true, true, true);
    
// GET /api/v2/events/recents - Get last 100 updated events as JSON
$app->get('/api/v2/events/recents', function (Request $request, Response $response, array $args) use ($eventRepository, $jsonStorage) {
    $events = $eventRepository->getRecent(100);

    // Enhance events with source, views, links, and regions data
    $enhancedEvents = array_map(function ($event) use ($jsonStorage) {
        // Convert Event object to array (includes regions from eager loading)
        $eventArray = is_array($event) ? $event : $event->toArray();
        $uuid = $eventArray['id'];
        
        // Format timestamps (replace raw values)
        if (!empty($eventArray['start'])) {
            $eventArray['start'] = date('Y-m-d H:i:s', $eventArray['start']);
        }
        if (!empty($eventArray['end'])) {
            $eventArray['end'] = date('Y-m-d H:i:s', $eventArray['end']);
        }
        if (!empty($eventArray['peak'])) {
            $eventArray['peak'] = date('Y-m-d H:i:s', $eventArray['peak']);
        }
        if (!empty($eventArray['coordinate_time'])) {
            $eventArray['coordinate_time'] = date('Y-m-d H:i:s', $eventArray['coordinate_time']);
        }
        // created_at and updated_at are already formatted by Eloquent
        
        // Load source JSON data
        $sourceData = $jsonStorage->load("/u/apps/data/sources/{$uuid}.json");
        $eventArray['source'] = $sourceData ?: null;
        
        // Load views JSON data
        $viewsData = $jsonStorage->load("/u/apps/data/views/{$uuid}.json");
        $eventArray['views'] = $viewsData ?: [];
        
        // Load links JSON data
        $linksData = $jsonStorage->load("/u/apps/data/links/{$uuid}.json");
        // Links can be either an array or object, preserve what's loaded
        $eventArray['link'] = $linksData;
        
        // Add API links - replace id with url, replace source with source_url
        $apiUrl = rtrim($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', '/');
        
        // Replace id with url and source with source_url
        $reorderedArray = [];
        foreach ($eventArray as $key => $value) {
            if ($key === 'id') {
                $reorderedArray['url'] = "{$apiUrl}/api/v2/events/{$uuid}";
            } elseif ($key === 'source') {
                $reorderedArray['source_url'] = "{$apiUrl}/api/v2/events/{$uuid}/source";
            } else {
                $reorderedArray[$key] = $value;
            }
        }
        
        return $reorderedArray;
    }, $events);
    
    $response->getBody()->write(json_encode($enhancedEvents));
    return $response->withHeader('Content-Type', 'application/json');
});

// GET /api/v1/events/{source}/observation/{timestamp} - Get events happening at timestamp for specific source
$app->get('/api/v1/events/{source}/observation/{timestamp}', function (Request $request, Response $response, array $args) use ($eventRepository, $coordinator, $jsonStorage) {

    $source = strtoupper($args['source']);
    
    // Validate source
    if (!in_array($source, ['CCMC', 'HEK', 'WSA', 'RHESSI'])) {
        $error = ['error' => 'Invalid source. Must be one of: CCMC, HEK, WSA, RHESSI'];
        $response->getBody()->write(json_encode($error, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }
    $timestamp = $args['timestamp'];
    
    // Parse timestamp using TimestampParser
    $timestampParser = new TimestampParser();
    try {
        $parsedTimestamp = $timestampParser->parse($timestamp);
    } catch (Exception $e) {
        $error = ['error' => 'Invalid timestamp or date format: ' . $e->getMessage()];
        $response->getBody()->write(json_encode($error, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }
    
    // Get events that were happening at the specified timestamp using repository
    $eventsArray = $eventRepository->findActiveAtTime($source, $parsedTimestamp);
    
    // Batch rotate coordinates using coordinator from bootstrap
    $rotatedCoordinates = $coordinator->rotateAll($eventsArray, $parsedTimestamp);
    
    // Apply rotated coordinates to events
    $eventsWithRotatedCoords = [];
    foreach ($eventsArray as $event) {
        $eventId = $event['id'];
        
        // Add rotated coordinates if available
        if (isset($rotatedCoordinates[$eventId])) {
            // Map hpc_x/hpc_y to rotated_hv_hpc_x/rotated_hv_hpc_y for backward compatibility
            $rotated = $rotatedCoordinates[$eventId];
            $event['hv_hpc_x'] = $rotated['hpc_x'] ?? null;
            $event['hv_hpc_y'] = $rotated['hpc_y'] ?? null;
            if (isset($rotated['rotation_error'])) {
                $event['coordinate_rotation_error'] = $rotated['rotation_error'];
            }
        }
        
        $eventsWithRotatedCoords[] = $event;
    }
    
    // Use Legacy formatter to format events
    $legacyResponse = new LegacyEventResponse($jsonStorage);
    $formattedEvents = $legacyResponse->formatEvents($eventsWithRotatedCoords, true);
    
    $response->getBody()->write(json_encode($formattedEvents, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// V2: Get events at observation time (using EloquentRepository directly)
$app->get('/api/v2/events/{source}/observation/{timestamp}', 
    function (Request $request, Response $response, array $args) use ($eventRepository, $jsonStorage) {
        try {
            $source = strtoupper($args['source']);
            
            // Validate source
            if (!in_array($source, ['CCMC', 'HEK', 'WSA', 'RHESSI'])) {
                throw new Exception('Invalid source. Must be one of: CCMC, HEK, WSA, RHESSI');
            }
            $timestamp = $args['timestamp'];
            
            // Parse timestamp using TimestampParser
            $timestampParser = new TimestampParser();
            $parsedTimestamp = $timestampParser->parse($timestamp);
            
            $events = $eventRepository->findActiveAtTime($source, $parsedTimestamp);
            
            // Apply coordinate transformation to observation time
            $observationTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);
            
            $eventsWithRotatedCoords = array_map(function($eventArray) use ($observationTime, $jsonStorage) {
                try {
                    $eventTime = date('Y-m-d\TH:i:s\Z', $eventArray['start']);
                    
                    // Convert HGS coordinates to HPC at observation time
                    $rotatedCoords = Coordinator::Hgs2Hpc(
                        $eventArray['hv_hpc_x'],   // HGS latitude (stored in hv_hpc_x)
                        $eventArray['hv_hpc_y'],   // HGS longitude (stored in hv_hpc_y)
                        $eventTime,
                        $observationTime
                    );
                    
                    $eventArray['rotated_hv_hpc_x'] = $rotatedCoords['x'];
                    $eventArray['rotated_hv_hpc_y'] = $rotatedCoords['y'];
                    $eventArray['observation_time'] = $observationTime;
                    $eventArray['original_hgs_lat'] = $eventArray['hv_hpc_x'];
                    $eventArray['original_hgs_lon'] = $eventArray['hv_hpc_y'];
                    
                    return $eventArray;
                    
                } catch (Exception $e) {
                    // If coordinate rotation fails, keep original coordinates
                    $eventArray['rotated_hv_hpc_x'] = $eventArray['hv_hpc_x'];
                    $eventArray['rotated_hv_hpc_y'] = $eventArray['hv_hpc_y'];
                    $eventArray['coordinate_rotation_error'] = $e->getMessage();
                    $eventArray['observation_time'] = $observationTime;
                }
                
                // Load source, views, and links data
                $uuid = $eventArray['id'];
                
                // Load source JSON data
                $sourceData = $jsonStorage->load("/u/apps/data/sources/{$uuid}.json");
                $eventArray['source'] = $sourceData ?: null;
                
                // Load views JSON data
                $viewsData = $jsonStorage->load("/u/apps/data/views/{$uuid}.json");
                $eventArray['views'] = $viewsData ?: [];
                
                // Load links JSON data
                $linksData = $jsonStorage->load("/u/apps/data/links/{$uuid}.json");
                $eventArray['link'] = $linksData;
                
                return $eventArray;
            }, $events);
            
            $result = [
                'source' => $source,
                'observation_time' => $parsedTimestamp,
                'observation_date' => date('Y-m-d H:i:s', $parsedTimestamp),
                'events_found' => count($eventsWithRotatedCoords),
                'events' => $eventsWithRotatedCoords
            ];
            
            $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $error = ['error' => $e->getMessage()];
            $response->getBody()->write(json_encode($error, JSON_PRETTY_PRINT));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }
);

// GET /api/v2/events/{uuid} - Get a single event by UUID
$app->get('/api/v2/events/{uuid}', function (Request $request, Response $response, array $args) use ($eventRepository, $jsonStorage) {
    $uuid = $args['uuid'];
    
    // Get event from repository using findById (id field is UUID in database)
    $event = $eventRepository->findById($uuid);
    
    if (!$event) {
        $error = ['error' => 'Event not found'];
        $response->getBody()->write(json_encode($error, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }
    
    // Convert Event object to array (regions are auto-loaded)
    $eventArray = $event->toArray();
    
    // Add formatted dates
    $eventArray['start_formatted'] = date('Y-m-d H:i:s', $eventArray['start']);
    $eventArray['end_formatted'] = date('Y-m-d H:i:s', $eventArray['end']);
    if (!empty($eventArray['peak'])) {
        $eventArray['peak_formatted'] = date('Y-m-d H:i:s', $eventArray['peak']);
    }
    if (!empty($eventArray['coordinate_time'])) {
        $eventArray['coordinate_time_formatted'] = date('Y-m-d H:i:s', $eventArray['coordinate_time']);
    }
    
    // Load source JSON data
    $sourceData = $jsonStorage->load("/u/apps/data/sources/{$uuid}.json");
    $eventArray['source'] = $sourceData ?: null;
    
    // Load views JSON data
    $viewsData = $jsonStorage->load("/u/apps/data/views/{$uuid}.json");
    $eventArray['views'] = $viewsData ?: [];
    
    // Load links JSON data
    $linksData = $jsonStorage->load("/u/apps/data/links/{$uuid}.json");
    $eventArray['link'] = $linksData;
    
    $response->getBody()->write(json_encode($eventArray, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// GET /api/v2/events/{uuid}/source - Get raw source data for a single event
$app->get('/api/v2/events/{uuid}/source', function (Request $request, Response $response, array $args) use ($eventRepository, $jsonStorage) {
    $uuid = $args['uuid'];
    
    // Verify event exists
    $event = $eventRepository->findById($uuid);
    if (!$event) {
        $error = ['error' => 'Event not found'];
        $response->getBody()->write(json_encode($error, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }
    
    // Load source JSON data
    $sourceData = $jsonStorage->load("/u/apps/data/sources/{$uuid}.json");
    if (!$sourceData) {
        $error = ['error' => 'Source data not found'];
        $response->getBody()->write(json_encode($error, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }
    
    $response->getBody()->write(json_encode($sourceData, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Test HEEQ to Stonyhurst transformation
$app->get('/test', function (Request $request, Response $response, array $args) {
    $input = "15.0 -2.0 2024-05-11T03:24:00Z\n130.0 10.0 2024-05-11T12:00:00Z\n";
    
    // Set environment variables for SunPy to use /tmp
    $env = [
        'SUNPY_CONFIGDIR' => '/tmp/sunpy_config',
        'XDG_CONFIG_HOME' => '/tmp',
        'HOME' => '/tmp'
    ];
    
    $process = proc_open('/usr/local/bin/heeq_to_stonyhurst', [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ], $pipes, null, $env);
    
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    
    $result = [
        'input' => trim($input),
        'output' => trim($output),
        'errors' => trim($errors)
    ];
    
    $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Default route
$app->get('/', function (Request $request, Response $response, array $args) {
    $data = [
        'message' => 'Helioviewer Events API',
        'endpoints' => [
            'v1' => [
                '/api/v1/events/{source}/observation/{timestamp}' => 'Get events at observation time with coordinate rotation'
            ],
            'v2' => [
                '/api/v2/events' => 'Get last 100 updated events with source, views, and links',
                '/api/v2/events/{uuid}' => 'Get a single event by UUID',
                '/api/v2/events/{source}/observation/{timestamp}' => 'Get events at observation time (enhanced)'
            ]
        ]
    ];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
