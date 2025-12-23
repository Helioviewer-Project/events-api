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

// ===========================
// Event Routes
// ===========================
$app->get('/api/v2/events/recents', [$eventController, 'getRecents']);

$app->get('/api/v1/events/{source}/observation/{timestamp}', [$eventController, 'getByObservationV1']);

$app->get('/api/v2/events/{source}/observation/{timestamp}', [$eventController, 'getByObservation']);

$app->get('/api/v2/events/{uuid:[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}}',
    [$eventController, 'getByUuid']);

$app->get('/api/v2/events/{uuid:[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}}/source',
    [$eventController, 'getEventSource']);

// ===========================
// Region Routes
// ===========================
$app->get('/api/v2/regions', [$regionController, 'getAll']);

$app->get('/api/v2/regions/{organization}/{external_id}', [$regionController, 'getByOrganizationAndId']);

// ===========================
// Statistics Routes
// ===========================
$app->get('/api/v2/stats', [$statsController, 'getStats']);

// ===========================
// HTML Page Routes
// ===========================
$app->get('/stats', [$pageController, 'statsPage']);

$app->get('/active-regions', [$pageController, 'predictionsPage']);

$app->get('/', [$pageController, 'home']);

// Run application
$app->run();