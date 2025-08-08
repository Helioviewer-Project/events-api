<?php

$container = require __DIR__ . '/../src/container.php';

// Get services from container
$coordinator = $container['coordinator'];
$jsonStorage = $container['jsonStorage'];
$eventRepository = $container['eventRepository'];

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Helioviewer\EventsApi\Models\Event;
use HelioviewerEventInterface\Coordinator\Coordinator;
use Helioviewer\EventsApi\Sources\JsonSource;
use Helioviewer\EventsApi\Utils\TimestampParser;
use Helioviewer\EventsApi\Response\Legacy as LegacyEventResponse;

// Create Slim app
$app = AppFactory::create();

// Use repository from container

// Add routing middleware
$app->addRoutingMiddleware();

// Add error handling
$app->addErrorMiddleware(true, true, true);
    
// GET /api/v2/events - Get last 100 updated events as JSON
$app->get('/api/v2/events', function (Request $request, Response $response, array $args) use ($eventRepository, $jsonStorage) {
    $events = $eventRepository->getRecent(100);
    
    // Enhance events with source, views, and links data
    $enhancedEvents = array_map(function ($eventArray) use ($jsonStorage) {
        $uuid = $eventArray['id'];
        
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
        
        return $eventArray;
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
            $event['rotated_hv_hpc_x'] = $rotated['hpc_x'] ?? null;
            $event['rotated_hv_hpc_y'] = $rotated['hpc_y'] ?? null;
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
                '/api/v2/events/{source}/observation/{timestamp}' => 'Get events at observation time (enhanced)'
            ]
        ]
    ];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
