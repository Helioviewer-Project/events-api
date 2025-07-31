<?php

require_once __DIR__ . '/../bootstrap.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;
use HelioviewerEventInterface\Types\HelioviewerEvent;
use HelioviewerEventInterface\Sources as EventInterfaceSources;
use Helioviewer\EventsApi\Models\Event;
use HelioviewerEventInterface\Coordinator\Coordinator;
use Helioviewer\EventsApi\Repositories\EloquentRepository;
use Helioviewer\EventsApi\Sources\JsonSource;
use Helioviewer\EventsApi\Utils\TimeRange;

// Create Slim app
$app = AppFactory::create();

// Create repository directly
$repository = new EloquentRepository();

// Add routing middleware
$app->addRoutingMiddleware();

// Add error handling
$app->addErrorMiddleware(true, true, true);

// GET /events - Get first 100 events as JSON
$app->get('/events', function (Request $request, Response $response, array $args) {
    $events = Event::all();
    
    $response->getBody()->write(json_encode($events, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});


// curl "https://www.lmsal.com/hek/her?cmd=search&cosec=2&type=column&event_type=ar&event_starttime=2025-04-14T00:00:00&event_endtime=2025-04-14T00:00:00&event_coordsys=helioprojective&x1=-30000&x2=30000&y1=-30000&y2=30000&param0=ar_noaanum&op0==&value0=14056&param1=frm_name&op1==&value1=NOAA%20SWPC%20Observer&return=required" | jq .

// GET /events/{source}/observation/{timestamp} - Get events happening at timestamp for specific source
$app->get('/events/{source:(?i)(CCMC|HEK|WSA|RHESSI)}/observation/{timestamp:(\d{10}|\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})}', function (Request $request, Response $response, array $args) {

    $source = strtoupper($args['source']);
    $timestamp = $args['timestamp'];
    
    // Get source ID using AbstractSource constants
    $sourceId = constant("Helioviewer\\EventsApi\\Sources\\AbstractSource::{$source}");
    
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

// V2: Get events at observation time (using EloquentRepository directly)
$app->get('/v2/events/{source:(?i)(CCMC|HEK|WSA|RHESSI)}/observation/{timestamp:(\d{10}|\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})}', 
    function (Request $request, Response $response, array $args) use ($repository) {
        try {
            $source = strtoupper($args['source']);
            $timestamp = $args['timestamp'];
            
            // Parse timestamp
            if (is_numeric($timestamp)) {
                $parsedTimestamp = (int) $timestamp;
            } else {
                $time = strtotime($timestamp);
                if ($time === false) {
                    throw new Exception('Invalid timestamp format');
                }
                $parsedTimestamp = $time;
            }
            
            $events = $repository->findActiveAtTime($source, $parsedTimestamp);
            
            // Apply coordinate transformation to observation time
            $observationTime = date('Y-m-d\TH:i:s\Z', $parsedTimestamp);
            
            $eventsWithRotatedCoords = array_map(function($eventArray) use ($observationTime) {
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
                    
                    return $eventArray;
                }
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
    $data = ['message' => 'Events API - Use /events to get events data'];
    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
