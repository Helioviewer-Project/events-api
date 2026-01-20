<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events;

use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use Helioviewer\EventsApi\Events\Processors\ProcessorInterface;
use Helioviewer\EventsApi\Events\Repositories\RepositoryInterface;
use Helioviewer\EventsApi\Regions\Repositories\RepositoryInterface as RegionRepositoryInterface;
use Helioviewer\EventsApi\Regions\Region;
use Helioviewer\EventsApi\Storage\Json\JsonStorageInterface;
use Helioviewer\EventsApi\Utils\TimeRange;
use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Exception\CoordinateResolutionException;
use Helioviewer\EventsApi\Exception\SourceException;
use Helioviewer\EventsApi\Exception\InvalidEventException;
use Psr\Log\LoggerInterface;
use Monolog\Processor\TagProcessor;
// Sources for createStandard factory method
use Helioviewer\EventsApi\Events\Sources\HEK\Source as HEKSource;
use Helioviewer\EventsApi\Events\Sources\CCMC\DonkiFlareSource;
use Helioviewer\EventsApi\Events\Sources\CCMC\DonkiCmeSource;
use Helioviewer\EventsApi\Events\Sources\CCMC\FlareScoreboardSource;
// Processors for createStandard factory method
use Helioviewer\EventsApi\Events\Processors\HEK\EventTypeProcessor as HEKEventTypeProcessor;
use Helioviewer\EventsApi\Events\Processors\HEK\ARProcessor as HEKARProcessor;
use Helioviewer\EventsApi\Events\Processors\HEK\CEProcessor as HEKCEProcessor;
use Helioviewer\EventsApi\Events\Processors\HEK\CHProcessor as HEKCHProcessor;
use Helioviewer\EventsApi\Events\Processors\HEK\EFProcessor as HEKEFProcessor;
use Helioviewer\EventsApi\Events\Processors\HEK\FIProcessor as HEKFIProcessor;
use Helioviewer\EventsApi\Events\Processors\HEK\FlareProcessor as HEKFlareProcessor;
use Helioviewer\EventsApi\Events\Processors\HEK\SGProcessor as HEKSGProcessor;
use Helioviewer\EventsApi\Events\Processors\CCMC\DonkiFlareProcessor;
use Helioviewer\EventsApi\Events\Processors\CCMC\DonkiCmeProcessor;
use Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard\Processor as FlareScoreboardProcessor;
use Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard\DaffProcessor;
use Helioviewer\EventsApi\Events\Processors\CCMC\FlareScoreboard\AssaProcessor;

/**
 * Event Collection Service
 *
 * Main orchestrator service responsible for collecting, processing, and storing
 * solar events from multiple data sources. Manages the registration and coordination
 * of data sources and processors, handling the complete data pipeline from
 * raw data fetching to processed event storage.
 *
 * The service supports:
 * - Multiple data source registration and management
 * - Event processor registration and automatic matching
 * - Batch and individual source data collection
 * - Error handling and logging for failed operations
 * - Statistics and monitoring of collection operations
 *
 * @package Helioviewer\EventsApi\Collector
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class Collector
{
    /**
     * Registered data sources indexed by path.
     *
     * @var array<string, SourceInterface>
     */
    private array $sources = [];

    /**
     * Registered event processors.
     *
     * @var array<ProcessorInterface>
     */
    private array $processors = [];

    /**
     * Construct a new EventCollector instance.
     *
     * @param RepositoryInterface $repository Repository for event persistence
     * @param RegionRepositoryInterface $regionRepository Repository for region persistence
     * @param JsonStorageInterface $json_storage Storage service for raw JSON data
     * @param JsonStorageInterface $failure_storage Storage service for failure data (non-sharded)
     * @param LoggerInterface|null $logger Logger for recording collection activities
     */
    public function __construct(
        private RepositoryInterface $repository,
        private RegionRepositoryInterface $regionRepository,
        private JsonStorageInterface $json_storage,
        private JsonStorageInterface $failure_storage,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
    }
    
    /**
     * Create a pre-configured collector with standard sources and processors
     *
     * This factory method creates a collector instance with all the standard
     * sources and processors configured as used in bin/collect.php
     *
     * @param RepositoryInterface $repository Repository for event persistence
     * @param RegionRepositoryInterface $regionRepository Repository for region persistence
     * @param JsonStorageInterface $json_storage Storage service for raw JSON data
     * @param JsonStorageInterface $failure_storage Storage service for failure data
     * @param \Helioviewer\EventsApi\Utils\CachedHttpClient $httpClient HTTP client for API calls
     * @param \Helioviewer\EventsApi\Jsoc\HarpService $harpService HARP service for coordinate resolution
     * @param \Helioviewer\EventsApi\Jsoc\NoaaService $noaaService NOAA service for coordinate resolution
     * @param LoggerInterface|null $logger Logger for recording activities
     * @return self Fully configured collector instance
     */
    public static function createStandard(
        RepositoryInterface $repository,
        RegionRepositoryInterface $regionRepository,
        JsonStorageInterface $json_storage,
        JsonStorageInterface $failure_storage,
        \Helioviewer\EventsApi\Utils\CachedHttpClient $httpClient,
        \Helioviewer\EventsApi\Jsoc\HarpService $harpService,
        \Helioviewer\EventsApi\Jsoc\NoaaService $noaaService,
        ?LoggerInterface $logger = null
    ): self {
        // Create collector instance
        $collector = new self($repository, $regionRepository, $json_storage, $failure_storage, $logger);
        
        // === SOURCES ===
        $collector->addSource('HEK', new HEKSource($httpClient));

        // === PROCESSORS ===
        // Specialized HEK processors
        $collector->addProcessor(new HEKARProcessor($logger));  // AR - Active Region
        $collector->addProcessor(new HEKCEProcessor($logger));  // CE - CME
        $collector->addProcessor(new HEKCHProcessor($logger));  // CH - Coronal Hole
        $collector->addProcessor(new HEKEFProcessor($logger));  // EF - Emerging Flux
        $collector->addProcessor(new HEKFIProcessor($logger));  // FI - Filament
        $collector->addProcessor(new HEKFlareProcessor($logger));  // FL - Flare (uses peak for coordinate_time)
        $collector->addProcessor(new HEKSGProcessor($logger));  // SG - Sigmoid

        // Generic HEK event type processors for remaining event types
        $hekEventTypes = [
            'CC',  // Coronal Cavity
            'CD',  // Coronal Dimming
            'CJ',  // Coronal Jet
            'CR',  // Coronal Rain
            'CW',  // Coronal Wave
            'ER',  // Eruption
            'FA',  // Filament Activation
            'FE',  // Filament Eruption
            'LP',  // Loop
            'OS',  // Oscillation
            'PG',  // Plage
            'SP',  // Spray Surge
            'SS',  // Sunspot
            // Less used ones
            'OT', // Other
            'NR', // Nothing Reported
            'TO', // Topological Object
            'HY', // Hypothesis
            'BU', // UVBurst
            'EE', // ExplosiveEvent
            'PB', // ProminenceBubble
            'PT', // PeacockTail
            'EP', // SEPs
            'IC', // ICMEs
            'SR', // SIRs
        ];

        foreach ($hekEventTypes as $eventType) {
            $collector->addProcessor(new HEKEventTypeProcessor($eventType, $logger));
        }

        $collector->addSource('CCMC>>DONKI>>CME', new DonkiCmeSource($httpClient));
        $collector->addSource('CCMC>>DONKI>>Solar Flares', new DonkiFlareSource($httpClient));

        // Prediction models
        $predictionModels = [
            'SIDC_Operator_REGIONS' => 'SIDC Operator',
            'BoM_flare1_REGIONS' => 'Bureau of Meteorology',
            'ASSA_1_REGIONS' => 'ASSA',
            'AMOS_v1_REGIONS' => 'AMOS',
            'ASAP_1_REGIONS' => 'ASAP',
            'MAG4_LOS_FEr_REGIONS' => 'MAG4 LoS FEr',
            'MAG4_LOS_r_REGIONS' => 'MAG4 LoS r',
            'DAFFS_REGIONS' => 'DAFFS',
            'MAG4_SHARP_FE_REGIONS' => 'MAG4 Sharp FE',
            'MAG4_SHARP_REGIONS' => 'MAG4 Sharp',
            'MAG4_SHARP_HMI_REGIONS' => 'MAG4 Sharp HMI',
            'AEffort_REGIONS' => 'AEffort',
        ];

        foreach ($predictionModels as $modelId => $modelName) {
            $collector->addSource("CCMC>>Solar Flare Predictions>>$modelName",
                new FlareScoreboardSource($modelId, $modelName, $httpClient));
        }

        // === PROCESSORS ===
        // DONKI processors don't need coordinate resolution (coordinates in raw data)
        $collector->addProcessor(new DonkiFlareProcessor($logger));
        $collector->addProcessor(new DonkiCmeProcessor($logger));

        // DAFF processor uses direct service integration (no resolvers)
        $daffProcessor = new DaffProcessor($harpService, $noaaService, $logger);
        $collector->addProcessor($daffProcessor);

        // ASSA processor with custom coordinate extraction
        $assaProcessor = new AssaProcessor($logger);
        $collector->addProcessor($assaProcessor);

        // FlareScoreboard processor reads coordinates directly from fields (no resolvers needed)
        $flareScoreboardProcessor = new FlareScoreboardProcessor($logger);
        $collector->addProcessor($flareScoreboardProcessor);
        
        return $collector;
    }
    
    /**
     * Register a data source for event collection with a specific path.
     *
     * Sources are indexed by their path for organized collection.
     * Duplicate paths will overwrite the previously registered source.
     *
     * @param string $path The path/key to register the source under
     * @param SourceInterface $source The data source to register
     *
     * @return void
     */
    public function addSource(string $path, SourceInterface $source): void
    {
        $this->sources[$path] = $source;
    }
    
    /**
     * Register an event processor for data transformation.
     *
     * Processors are responsible for converting raw data from sources
     * into standardized Event objects. Multiple processors can be registered
     * and the first matching processor will handle each raw record.
     *
     * @param ProcessorInterface $processor The event processor to register
     *
     * @return void
     */
    public function addProcessor(ProcessorInterface $processor): void
    {
        $this->processors[] = $processor;
    }
    
    /**
     * Get all registered sources.
     *
     * @return array<string, SourceInterface> Array of sources indexed by path
     */
    public function getSources(): array
    {
        return $this->sources;
    }
    
    /**
     * Collect and process events from all registered sources within the specified time range.
     *
     * Processes the time range in chunks according to the specified interval (default: 1 day).
     * This allows efficient processing of large date ranges while managing memory usage and API calls.
     *
     * @param TimeRange $range Time range for data collection
     * @param int $chunkInterval Number of days per processing chunk (default: 1)
     *
     * @return array<Event> Array of processed Event objects
     */
    public function collect(TimeRange $range, int $chunkInterval = 1): array
    {
        $allEvents = [];
        
        // Process all registered sources
        $sourcesToProcess = $this->sources;
        
        // Process in chunks based on interval
        $chunks = $range->splitByInterval($chunkInterval);
        
        foreach ($chunks as $index => $chunkRange) {
            $chunkDays = round($chunkRange->getDuration() / 86400, 1);
            $this->logger->info("Processing chunk " . ($index + 1) . "/" . count($chunks) . 
                              ": " . $chunkRange->getStartDate() . " to " . $chunkRange->getEndDate() . 
                              " ({$chunkDays} days)");
            
            foreach ($sourcesToProcess as $path => $source) {
                // Add tag processor for this source, date range and chunk number
                $tagProcessor = new TagProcessor([
                    'source' => $source->getName(),
                    'date_range' => $chunkRange->getStartDate() . " to " . $chunkRange->getEndDate(),
                    'chunk' => $index + 1
                ]);
                $this->logger->pushProcessor($tagProcessor);
                
                try {
                    $this->logger->info("Collecting");
                    $events = $this->collectFromSource($path, $source, $chunkRange);
                    $allEvents = array_merge($allEvents, $events);
                    $this->logger->info("Collected " . count($events) . " valid events");
                    
                    // Add delay after fetching each chunk's data (except for the last chunk)
                    // This prevents overwhelming APIs when fetching multiple chunks
                    if ($index < count($chunks) - 1) {
                        $sleepTime = mt_rand(500000, 1500000); // microseconds (0.5 to 1.5 seconds)
                        $this->logger->debug("Sleeping for " . round($sleepTime / 1000000, 2) . " seconds before next chunk");
                        usleep($sleepTime);
                    }
                } catch (SourceException $e) {
                    $this->logger->critical("Source exception: {$e->getSourcePath()} => {$e->getSourceName()}: {$e->getMessage()}");
                    $this->logger->debug("SourceException details: " . $e->getTraceAsString());
                    throw $e;
                } catch (\Exception $e) {
                    $this->logger->critical("Failed to collect: {$e->getMessage()}");
                    $this->logger->debug("Exception details: " . get_class($e) . " - " . $e->getTraceAsString());
                    throw $e;
                } finally {
                    // Remove the processor when done with this source
                    $this->logger->popProcessor();
                }
            }
        }
        
        return $allEvents;
    }

    /**
     * Collect and process events from a specific data source object.
     *
     * @param string $path The path/identifier for this source
     * @param SourceInterface $source The source object to collect from
     * @param TimeRange $range Time range for data collection
     *
     * @return array<Event> Array of processed Event objects
     */
    private function collectFromSource(string $path, SourceInterface $source, TimeRange $range): array
    {
        $sourceName = $source->getName();
        
        // Let exceptions bubble up - they'll be caught in the collect() method
        $rawData = $source->fetchRawData($range);
        
        $events = [];
        
        $totalRawRecords = count($rawData);
        $this->logger->info("Found {$totalRawRecords} raw event records");

        $processedCount = 0;
        foreach ($rawData as $index => $rawRecord) {

            $processorFound = false;

            foreach ($this->processors as $processor) {
                if ($processor->canProcess($source, $rawRecord)) {

                    $processorFound = true;

                    $processorClass = get_class($processor);
                    
                    try {

                        // Process the raw record into an unpersisted Event model
                        $event = $processor->process($rawRecord, $source);

                        // Extract remote ID for deduplication and set it
                        $event->remote_id = $source->getName() . ":" . $source->extractRawRecordId($rawRecord);

                        if(empty($event->path)) {
                            $event->path = $path;
                        } else {
                            $event->path = $path . '>>' .$event->path;
                        }
                        
                        // Store views, link, and region info temporarily before DB save
                        $tempViews = $event->legacy_views;
                        $tempLink = $event->legacy_link;
                        $tempRegionInfo = $event->region_info ?? null;
                        $tempRegionsInfo = $event->regions_info ?? null;
                        unset($event->legacy_views, $event->legacy_link, $event->region_info, $event->regions_info);

                        // Handle upsert logic: find existing event or create new one
                        $existingEvent = $this->repository->findByRemoteId($event->remote_id);
                        
                        if ($existingEvent) {
                            // Update existing event with new data
                            // Use toArray() to get casted values (getAttributes() returns raw uncasted values)
                            $existingEvent->fill($event->toArray());
                            $savedEvent = $this->repository->save($existingEvent);
                            $action = "Updated";
                        } else {
                            // Save new event
                            $savedEvent = $this->repository->save($event);
                            $action = "Created";
                        }
                        
                        // Now save JSON files using the UUID after successful DB save
                        $uuid = $savedEvent->id;
                        
                        // Save raw source data using sharded storage
                        $this->json_storage->storeById($uuid, 'sources', $rawRecord);
                        
                        // Save views data using sharded storage
                        if (!empty($tempViews)) {
                            $this->json_storage->storeById($uuid, 'views', $tempViews);
                        }
                        
                        // Save link data using sharded storage
                        if (!empty($tempLink)) {
                            $this->json_storage->storeById($uuid, 'links', $tempLink);
                        } else {
                            // Create default link structure when tempLink is empty
                            $defaultLink = [
                                'url' => $savedEvent->getUrl(),
                                'text' => 'Helioviewer Events API JSON'
                            ];
                            $this->json_storage->storeById($uuid, 'links', $defaultLink);
                        }
                        
                        // Handle region associations (single or multiple)
                        $regionsToProcess = [];
                        if ($tempRegionInfo) {
                            $regionsToProcess[] = $tempRegionInfo;
                        }
                        if ($tempRegionsInfo) {
                            $regionsToProcess = array_merge($regionsToProcess, $tempRegionsInfo);
                        }

                        foreach ($regionsToProcess as $regionInfo) {
                            $this->logger->debug("Processing region: {$regionInfo['organization']} {$regionInfo['external_id']}");
                            
                            // Find or create the region
                            $region = $this->regionRepository->findByOrganizationAndExternalId(
                                $regionInfo['organization'],
                                $regionInfo['external_id']
                            );
                            
                            if (!$region) {
                                $region = new Region();
                                $region->organization = $regionInfo['organization'];
                                $region->external_id = $regionInfo['external_id'];
                                $region = $this->regionRepository->save($region);
                                $this->logger->debug("Created new region: {$region->name} (ID: {$region->id})");
                            } else {
                                $this->logger->debug("Found existing region: {$region->name} (ID: {$region->id})");
                            }
                            
                            // Check if event is already associated with this region
                            $regionIds = $savedEvent->regions->pluck('id')->toArray();
                            if (!in_array($region->id, $regionIds)) {
                                $savedEvent->regions()->attach($region->id);
                                $this->logger->debug("Associated event {$savedEvent->id} with region {$region->name} (ID: {$region->id})");
                            } else {
                                $this->logger->debug("Event {$savedEvent->id} already associated with region {$region->name} (ID: {$region->id})");
                            }
                        }
                        
                        $events[] = $savedEvent;

                        $processedCount++;

                        // Log processing progress with event details and view link
                        $this->logger->info("{$action} event: {$savedEvent->remote_id} | {$savedEvent->getUrl()}");

                        
                    } catch (InvalidEventException | CoordinateResolutionException $e) {

                        $failureId = hash('sha256', json_encode($rawRecord)) . '.json';
                        
                        // Set path based on exception type
                        $failurePath = match (true) {
                            $e instanceof InvalidEventException => "/u/apps/data/failures/invalid_events/{$sourceName}/{$failureId}",
                            $e instanceof CoordinateResolutionException => "/u/apps/data/failures/coordinate_errors/{$sourceName}/{$failureId}"
                        };

                        $apiUrl = rtrim($_ENV['APIURL'], '/');

                        $failureURL = match (true) {
                            $e instanceof InvalidEventException => "{$apiUrl}/static/failures/invalid_events/{$sourceName}/{$failureId}",
                            $e instanceof CoordinateResolutionException => "{$apiUrl}/static/failures/coordinate_errors/{$sourceName}/{$failureId}"
                        };
                        
                        // Save failure using hash of raw record
                        $failureData = [
                            'error' => $e->getMessage(),
                            'source' => $sourceName,
                            'raw_record' => $rawRecord,
                            'timestamp' => time(),
                            'exception_class' => get_class($e)
                        ];
                        
                        // Store the failure and get the actual file path (using non-sharded storage)
                        $storedPath = $this->failure_storage->store($failurePath, $failureData);

                        // Log warning with exception details
                        $this->logger->warning((new \ReflectionClass($e))->getShortName() . " | {$e->getMessage()} | {$failureURL}");
                        
                    }

                    // First matching processor wins - stop processing other processors
                    break;
                }
            }

            if (!$processorFound) {
                $remoteId = $source->extractRawRecordId($rawRecord);
                $this->logger->warning("No processor found for event | source: {$path} | remote_id: {$remoteId}");
            }
        }
        
        return $events;
    }
}
