<?php

require __DIR__ . '/../src/bootstrap.php';

use Slim\Factory\AppFactory;
use Helioviewer\EventsApi\Utils\Container;

$container = Container::getInstance();
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Helioviewer\EventsApi\Api\Controllers\EventController;
use Helioviewer\EventsApi\Api\Controllers\RegionController;
use Helioviewer\EventsApi\Api\Controllers\StatsController;
use Helioviewer\EventsApi\Api\Controllers\PageController;
use Helioviewer\EventsApi\Api\Controllers\HelioviewerController;

// Create Slim app
$app = AppFactory::create();

// Add routing middleware
$app->addRoutingMiddleware();

// Add error handling
$app->addErrorMiddleware(true, true, true);

// Initialize all controllers
$eventController = new EventController($container);
$regionController = new RegionController($container);
$statsController = new StatsController($container);
$pageController = new PageController($container);
$helioviewerController = new HelioviewerController($container);

// ===========================
// Helioviewer Routes (Legacy format for Helioviewer.org)
// ===========================
$app->get('/helioviewer/events/{source}/observation/{timestamp}', [$helioviewerController, 'getByObservation']);
$app->post('/helioviewer/events/from/{from}/to/{to}', [$helioviewerController, 'getEventsByPaths']);
$app->post('/helioviewer/distributions/size/{size}/from/{from}/to/{to}', [$helioviewerController, 'getDistribution']);
$app->post('/helioviewer/events/{sources}/observations', [$helioviewerController, 'getBatchObservations']);

// ===========================
// /api/* Routes
// v1 is the canonical version; v2 is kept as an alias so older persisted
// links (e.g. https://events.helioviewer.org/api/v2/events/<uuid>) keep resolving.
// ===========================
foreach (['v1', 'v2'] as $apiVersion) {
    // Event Routes
    $app->get("/api/{$apiVersion}/events/recents", [$eventController, 'getRecents']);

    $app->get("/api/{$apiVersion}/events/{source}/observation/{timestamp}", [$eventController, 'getByObservation']);

    $app->get("/api/{$apiVersion}/events/{uuid:[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}}",
        [$eventController, 'getByUuid']);

    $app->get("/api/{$apiVersion}/events/{uuid:[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}}/source",
        [$eventController, 'getEventSource']);

    // Region Routes
    $app->get("/api/{$apiVersion}/regions", [$regionController, 'getAll']);

    $app->get("/api/{$apiVersion}/regions/{organization}/{external_id}", [$regionController, 'getByOrganizationAndId']);

    // Statistics Routes
    $app->get("/api/{$apiVersion}/stats", [$statsController, 'getStats']);
}

// ===========================
// HTML Page Routes
// ===========================
$app->get('/stats', [$pageController, 'statsPage']);

$app->get('/active-regions', [$pageController, 'predictionsPage']);

$app->get('/plan', [$pageController, 'planPage']);

$app->get('/', [$pageController, 'home']);

// Run application
$app->run();