<?php

require __DIR__ . '/../src/bootstrap.php';

use Slim\Factory\AppFactory;
use Slim\Exception\HttpException;
use Helioviewer\EventsApi\Utils\Container;

$container = Container::getInstance();
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Helioviewer\EventsApi\Api\Controllers\EventController;
use Helioviewer\EventsApi\Api\Controllers\RegionController;
use Helioviewer\EventsApi\Api\Controllers\StatsController;
use Helioviewer\EventsApi\Api\Controllers\PageController;
use Helioviewer\EventsApi\Api\Controllers\HelioviewerController;
use Helioviewer\EventsApi\Api\Controllers\FailuresController;

// Create Slim app
$app = AppFactory::create();

// Add routing middleware
$app->addRoutingMiddleware();

// Tag Sentry events coming from the web entrypoint
$sentry = $container['sentry'];
$sentry->setTag('Type', 'web');

// Add error handling.
// displayErrorDetails=false so Slim does not leak stack/paths into responses.
// logErrors=true still writes full details to the server log.
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setDefaultErrorHandler(
    function (Request $request, \Throwable $exception, bool $displayErrorDetails) use ($app, $sentry) {
        // Use Slim's HttpException status codes (404, 405, 400, ...) when present
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;

        // Only report real server errors to Sentry — 4xx client errors shouldn't flood it
        if ($status >= 500) {
            $sentry->setContext('Request', [
                'method' => $request->getMethod(),
                'uri'    => (string) $request->getUri(),
            ]);
            $sentry->capture($exception);
        }

        // Static message per status code — never use the exception's own message
        // (it may embed file paths, SQL, env values, etc.)
        $message = match (true) {
            $status === 400 => 'Bad Request',
            $status === 401 => 'Unauthorized',
            $status === 403 => 'Forbidden',
            $status === 404 => 'Not Found',
            $status === 405 => 'Method Not Allowed',
            $status >= 500  => 'Server Error',
            default         => 'Error',
        };

        $response = $app->getResponseFactory()->createResponse();
        $response->getBody()->write(json_encode([
            'status' => $status,
            'error'  => $message,
        ]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
);

// Initialize all controllers
$eventController = new EventController($container);
$regionController = new RegionController($container);
$statsController = new StatsController($container);
$pageController = new PageController($container);
$helioviewerController = new HelioviewerController($container);
$failuresController = new FailuresController($container);

// ===========================
// Helioviewer Routes (Legacy format for Helioviewer.org)
// ===========================
$app->get('/helioviewer/events/{source}/observation/{timestamp}', [$helioviewerController, 'getByObservation']);
$app->post('/helioviewer/events/from/{from}/to/{to}', [$helioviewerController, 'getEventsByPaths']);
$app->post('/helioviewer/distributions/size/{size}/from/{from}/to/{to}', [$helioviewerController, 'getDistribution']);
$app->post('/helioviewer/events/{sources}/observations', [$helioviewerController, 'getBatchObservations']);
$app->post('/helioviewer/events/frames_with_selections', [$helioviewerController, 'getObservationsBySelection']);

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

    // Failures listing (JSON) — paginated/filterable
    $app->get("/api/{$apiVersion}/failures", [$failuresController, 'listJson']);
}

// ===========================
// HTML Page Routes
// ===========================
$app->get('/stats', [$pageController, 'statsPage']);

$app->get('/active-regions', [$pageController, 'predictionsPage']);

$app->get('/plan', [$pageController, 'planPage']);

$app->get('/exceptions', [$failuresController, 'page']);

$app->get('/', [$pageController, 'home']);

// Run application
$app->run();