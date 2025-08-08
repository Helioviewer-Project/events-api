<?php

declare(strict_types=1);

// === AUTOLOAD ===
require_once __DIR__ . '/../vendor/autoload.php';

// === IMPORTS ===
use Illuminate\Database\Capsule\Manager as Capsule;

// Services
use Helioviewer\EventsApi\Cache\RedisCache;
use Helioviewer\EventsApi\Utils\CachedHttpClient;
use Helioviewer\EventsApi\Coordinator\DanielCoordinator;
use Helioviewer\EventsApi\JsonStorage\LocalFile;
use Helioviewer\EventsApi\JSOC\HarpService;
use Helioviewer\EventsApi\JSOC\NoaaService;

// Repositories
use Helioviewer\EventsApi\Repositories\PostgresEventRepository;

// === HELPER FUNCTIONS ===
function pr($m): void {
    echo '<pre>';
    print_r($m);
    echo '</pre>';
}

function pre($m): void {
    pr($m);
    exit;
}

// === CONSTANTS ===
if (!defined('HV_COORDINATOR_URL')) {
    define('HV_COORDINATOR_URL', $_ENV['HV_COORDINATOR_URL'] ?? 'https://api.helioviewer.org/coordinate');
}

// === DATABASE INITIALIZATION ===
$capsule = new Capsule;

$capsule->addConnection([
    'driver' => 'pgsql',
    'host' => $_ENV['DB_HOST'] ?? 'postgres',
    'port' => $_ENV['DB_PORT'] ?? '5432',
    'database' => $_ENV['DB_NAME'] ?? 'eventsapi',
    'username' => $_ENV['DB_USER'] ?? 'eventsapi',
    'password' => $_ENV['DB_PASSWORD'] ?? 'eventsapi_pass',
    'charset' => 'utf8',
    'prefix' => '',
    'schema' => 'public',
    'sslmode' => 'prefer',
]);

// Make Capsule instance available globally
$capsule->setAsGlobal();
$capsule->bootEloquent();

// === REDIS INITIALIZATION ===
$redisHost = $_ENV['REDIS_HOST'] ?? 'redis';
$redisPort = (int)($_ENV['REDIS_PORT'] ?? 6379);
$redisDb = (int)($_ENV['REDIS_DB'] ?? 10);

$redis = new \Redis();
$redis->connect($redisHost, $redisPort);
$redis->select($redisDb);
$redis->ping();

// === SERVICE INSTANCES ===
// Core services
$redisCache = new RedisCache($redis, 'hv:events-api:');
$coordinator = new DanielCoordinator($redisCache);
$jsonStorage = new LocalFile();
$eventRepository = new PostgresEventRepository();

// HTTP and external services
$httpClient = new CachedHttpClient(null, $redisCache, 1800); // 30 minute cache
$harpService = new HarpService($httpClient, $redisCache);
$noaaService = new NoaaService($httpClient, $redisCache);
