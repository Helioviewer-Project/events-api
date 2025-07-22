<?php

require_once __DIR__ . '/../bootstrap.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;
use HelioviewerEventInterface\Types\HelioviewerEvent;
use HelioviewerEventInterface\Sources as EventInterfaceSources;
use Helioviewer\EventsApi\Models\Event;
use Helioviewer\EventsApi\Sources\Source;
use HelioviewerEventInterface\Coordinator\Coordinator;

// Create Slim app
$app = AppFactory::create();

// Add routing middleware
$app->addRoutingMiddleware();

// Add error handling
$app->addErrorMiddleware(true, true, true);

// GET /events - Get first 100 events as JSON
$app->get('/events', function (Request $request, Response $response, array $args) {
    $events = Event::limit(100)->get();
    
    $response->getBody()->write(json_encode($events, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// GET /events/{source}/observation/{timestamp} - Get events happening at timestamp for specific source
$app->get('/events/{source}/observation/{timestamp}', function (Request $request, Response $response, array $args) {
    $source = strtoupper($args['source']);
    $timestamp = $args['timestamp'];
    
    // Get source ID from constant
    $sourceId = null;
    switch ($source) {
        case 'CCMC':
            $sourceId = Source::CCMC;
            break;
        case 'HEK':
            $sourceId = Source::HEK;
            break;
        case 'WSA':
            $sourceId = Source::WSA;
            break;
        case 'RHESSI':
            $sourceId = Source::RHESSI;
            break;
        default:
            $error = ['error' => 'Invalid source. Valid sources: CCMC, HEK, WSA, RHESSI'];
            $response->getBody()->write(json_encode($error, JSON_PRETTY_PRINT));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }
    
    // Parse timestamp
    if (is_numeric($timestamp)) {
        $parsedTimestamp = (int) $timestamp;
    } else {
        try {
            $dateTime = new DateTime($timestamp);
            $parsedTimestamp = $dateTime->getTimestamp();
        } catch (Exception $e) {
            $error = ['error' => 'Invalid timestamp or date format'];
            $response->getBody()->write(json_encode($error, JSON_PRETTY_PRINT));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
    }
    
    // Get events that were happening at the specified timestamp
    $events = Event::where('source_id', $sourceId)
                   ->where('start', '<=', $parsedTimestamp)
                   ->where('end', '>=', $parsedTimestamp)
                   ->get();
    
    // Rotate coordinates to observation time
    $observationTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);
    
    $eventsWithRotatedCoords = $events->map(function ($event) use ($observationTime) {
        $eventArray = $event->toArray();
        
        // Get original event time for coordinate rotation
        $eventTime = date('Y-m-d\TH:i:s\Z', $event->start);
        
        try {
            // Convert HGS (Stonyhurst) coordinates to HPC at observation time
            // hv_hpc_x stores latitude, hv_hpc_y stores longitude
            $rotatedCoords = Coordinator::Hgs2Hpc(
                $event->hv_hpc_x,  // latitude (stored in hv_hpc_x)
                $event->hv_hpc_y,  // longitude (stored in hv_hpc_y)
                $eventTime, 
                $observationTime
            );
            
            // Add rotated HPC coordinates to the response
            $eventArray['rotated_hv_hpc_x'] = $rotatedCoords['x'];
            $eventArray['rotated_hv_hpc_y'] = $rotatedCoords['y'];
            $eventArray['observation_time'] = $observationTime;
            $eventArray['original_hgs_lat'] = $event->hv_hpc_x;  // Original latitude
            $eventArray['original_hgs_lon'] = $event->hv_hpc_y;  // Original longitude
            
        } catch (Exception $e) {
            // If coordinate rotation fails, keep original coordinates
            $eventArray['rotated_hv_hpc_x'] = $event->hv_hpc_x;
            $eventArray['rotated_hv_hpc_y'] = $event->hv_hpc_y;
            $eventArray['coordinate_rotation_error'] = $e->getMessage();
        }
        
        return $eventArray;
    });
    
    $response->getBody()->write(json_encode($eventsWithRotatedCoords, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Test endpoint
$app->get('/test', function (Request $request, Response $response, array $args) {
    
    // Get all available sources from Event Interface
    // $allSources = EventInterfaceSources::All();

    
    $sourcesWithTranslators = [
        [
            'path' => 'CCMC>>DONKI>>CME',
            'pin'  => 'C3', 
        ],
        [
            'path' => 'CCMC>>DONKI>>Solar Flares',
            'pin'  => 'F1', 
        ],
        [
            'path' => 'CCMC>>Solar Flare Predictions>>Bureau of Meteorology',
            'pin'  => 'FP', 
        ]
    ];
    
    
    $data = [
        'message' => 'Test endpoint for Event Interface sources',
        'available_sources' => $sourcesWithTranslators,
    ];
    
    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Default route
$app->get('/', function (Request $request, Response $response, array $args) {
    $data = ['message' => 'Events API - Use /events to get events data'];
    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
