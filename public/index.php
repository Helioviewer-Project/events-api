<?php

require_once __DIR__ . '/../bootstrap.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;

// Create Slim app
$app = AppFactory::create();

// Add routing middleware
$app->addRoutingMiddleware();

// Add error handling
$app->addErrorMiddleware(true, true, true);

// GET /events - Get all events
$app->get('/events', function (Request $request, Response $response, array $args) {
    $events = Capsule::table('events')->get();
    
    $response->getBody()->write(json_encode($events, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Default route
$app->get('/', function (Request $request, Response $response, array $args) {
    $data = ['message' => 'Events API - Use /events to get events data'];
    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
