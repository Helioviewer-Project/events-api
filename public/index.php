<?php

require_once __DIR__ . '/../bootstrap.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;
use HelioviewerEventInterface\Types\HelioviewerEvent;
use HelioviewerEventInterface\Sources as EventInterfaceSources;
use Helioviewer\EventsApi\Models\Event;

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
