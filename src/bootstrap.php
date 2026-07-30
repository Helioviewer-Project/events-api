<?php

declare(strict_types=1);

// === AUTOLOAD ===
require_once __DIR__ . '/../vendor/autoload.php';

// === ERROR HANDLING - Convert errors to exceptions (except deprecations) ===
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Convert PHP errors to exceptions (excluding deprecations)
set_error_handler(function ($severity, $message, $file, $line) {
    // Ignore deprecation warnings
    if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
        return false;
    }
    
    // Respect error_reporting level
    if (!(error_reporting() & $severity)) {
        return false;
    }
    
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Handle fatal errors on shutdown
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Log to stderr since logger might not be available
        error_log("Fatal error: {$error['message']} in {$error['file']} on line {$error['line']}");
    }
});

// === ENVIRONMENT VARIABLES ===
// Check if .env file exists - required for application to run
if (!file_exists(__DIR__ . '/../.env')) {
    // Set HTTP 500 status if not CLI
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
    }
    
    throw new ErrorException(
        ".env configuration file not found. Please copy .env.example to .env and configure your settings.",
        500,  // Use 500 as error code
        E_ERROR,
        __FILE__,
        __LINE__
    );
}

// Load .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// === OPCACHE - Disable in non-production environments ===
if (($_ENV['APP_ENV'] ?? 'development') !== 'production' && function_exists('opcache_reset')) {
    ini_set('opcache.enable', '0');
}

// === IMPORTS ===
use Illuminate\Database\Capsule\Manager as Capsule;

// Logging
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Formatter\LineFormatter;

// Services
use Helioviewer\EventsApi\Storage\RedisCache;
use Helioviewer\EventsApi\Utils\CachedHttpClient;
use Helioviewer\EventsApi\Coordinator\HttpCoordinator;
use Helioviewer\EventsApi\Coordinator\CoordinateRotator;
use Helioviewer\EventsApi\Coordinator\FailoverCoordinator;
use Helioviewer\EventsApi\Coordinator\HPC\HPCResolver;
use Helioviewer\EventsApi\Storage\Json\LocalFile;
use Helioviewer\EventsApi\Storage\Json\ShardedLocalFile;
use Helioviewer\EventsApi\Jsoc\HarpService;
use Helioviewer\EventsApi\Jsoc\NoaaService;

// Repositories
use Helioviewer\EventsApi\Events\Repositories\Postgres;
use Helioviewer\EventsApi\Regions\Repositories\Postgres as RegionPostgres;
use Helioviewer\EventsApi\Distributions\Repositories\Postgres as DistributionPostgres;

// Sentry
use Helioviewer\EventsApi\Sentry\Client as SentryClient;
use Helioviewer\EventsApi\Sentry\VoidClient as SentryVoidClient;

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
$jsonStorage = new ShardedLocalFile('/u/apps/data');
$failureStorage = new LocalFile();
$eventRepository = new Postgres();
$regionRepository = new RegionPostgres();

// === LOGGER INITIALIZATION ===
// Get log level from environment (default to DEBUG for current verbose behavior)
$logLevel = strtoupper($_ENV['LOG_LEVEL'] ?? 'DEBUG');
$monologLevel = match($logLevel) {
    'EMERGENCY' => Logger::EMERGENCY,
    'ALERT' => Logger::ALERT,
    'CRITICAL' => Logger::CRITICAL,
    'ERROR' => Logger::ERROR,
    'WARNING' => Logger::WARNING,
    'NOTICE' => Logger::NOTICE,
    'INFO' => Logger::INFO,
    'DEBUG' => Logger::DEBUG,
    default => Logger::DEBUG
};

// Create logger instance
$logger = new Logger('hv.events.api');

// Create logs directory if it doesn't exist
$logsDir = '/u/apps/data/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Custom formatter that adds tags cleanly
class TagLineFormatter extends LineFormatter {
    public function format(\Monolog\LogRecord $record): string {
        $output = parent::format($record);
        
        // Add tags in a clean format if present
        if (!empty($record->extra['tags'])) {
            $tags = $record->extra['tags'];
            $tagStr = '';
            
            if (isset($tags['source'])) {
                $tagStr = $tags['source'];
            }
            
            if (isset($tags['date'])) {
                $tagStr .= ($tagStr ? ' | ' : '') . $tags['date'];
            }
            
            if (isset($tags['day'])) {
                $tagStr .= ($tagStr ? ' | Day ' : 'Day ') . $tags['day'];
            }
            
            if (isset($tags['processor'])) {
                $tagStr .= ($tagStr ? ' | ' : '') . $tags['processor'];
            }
            
            if ($tagStr) {
                $output .= " [{$tagStr}]";
            }
        }
        
        return $output;
    }
}

// Configure line formatter (no \n for console since error_log adds one)
$formatter = new TagLineFormatter(
    "[%datetime%] %channel%.%level_name%: %message%",
    'Y-m-d H:i:s',
    false,  // allowInlineLineBreaks
    true    // ignoreEmptyContextAndExtra
);

// File formatter needs the newline
$fileFormatter = new TagLineFormatter(
    "[%datetime%] %channel%.%level_name%: %message%\n",
    'Y-m-d H:i:s',
    false,  // allowInlineLineBreaks
    true    // ignoreEmptyContextAndExtra
);

// Add rotating file handler (daily rotation, keep 7 days)
$fileHandler = new RotatingFileHandler($logsDir . '/app.log', 7, Logger::DEBUG);
$fileHandler->setFormatter($fileFormatter);
$logger->pushHandler($fileHandler);

// Add console handler (respects LOG_LEVEL environment variable)
$consoleHandler = new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $monologLevel);
$consoleHandler->setFormatter($formatter);
$logger->pushHandler($consoleHandler);

// === SENTRY INITIALIZATION ===
$sentryConfig = [
    'environment' => $_ENV['APP_ENV'] ?? 'development',
    'sample_rate' => (float)($_ENV['SENTRY_SAMPLE_RATE'] ?? 0.1),
    'traces_sample_rate' => (float)($_ENV['SENTRY_TRACES_SAMPLE_RATE'] ?? 0.0),
    'dsn' => $_ENV['SENTRY_DSN'] ?? '',
];
$sentryEnabled = ($_ENV['SENTRY_ENABLED'] ?? 'false') === 'true';
$sentry = $sentryEnabled
    ? new SentryClient($sentryConfig)
    : new SentryVoidClient($sentryConfig);

// HTTP and external services
$httpClient = new CachedHttpClient(null, $redisCache, 120, 'http_client:', $logger); // 2 minute cache
$coordinator = new HttpCoordinator($httpClient, $logger);
$backup_coordinator = new HttpCoordinator($httpClient, $logger, 'http://coordinator');
$harpService = new HarpService($httpClient, $redisCache, $logger);
$noaaService = new NoaaService($httpClient, $redisCache, $logger);
$failoverCoordinator = new FailoverCoordinator($coordinator, $backup_coordinator, $logger, $sentry);
$hpcResolver = HPCResolver::createDefault($failoverCoordinator, $logger);
$coordinateRotator = new CoordinateRotator($failoverCoordinator, $hpcResolver, $logger, $redisCache);
$distributionRepository = new DistributionPostgres($redisCache, $logger);

// Initialize container with all services
\Helioviewer\EventsApi\Utils\Container::setServices([
    // Core services
    'cache' => $redisCache,
    'coordinateRotator' => $coordinateRotator,
    'hpcResolver' => $hpcResolver,
    'jsonStorage' => $jsonStorage,
    'failureStorage' => $failureStorage,
    'eventRepository' => $eventRepository,
    'regionRepository' => $regionRepository,
    'distributionRepository' => $distributionRepository,
    'logger' => $logger,
    'sentry' => $sentry,

    // HTTP and external services
    'httpClient' => $httpClient,
    'harp' => $harpService,
    'noaa' => $noaaService,
]);
