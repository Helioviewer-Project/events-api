<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events;

use Helioviewer\EventsApi\Events\Sources\SourceInterface;
use Helioviewer\EventsApi\Events\Processors\ProcessorInterface;
use Helioviewer\EventsApi\Events\Repositories\RepositoryInterface;
use Helioviewer\EventsApi\Regions\Repositories\RepositoryInterface as RegionRepositoryInterface;
use Helioviewer\EventsApi\Distributions\Repositories\RepositoryInterface as DistributionRepositoryInterface;
use Helioviewer\EventsApi\Regions\Region;
use Helioviewer\EventsApi\Storage\Json\JsonStorageInterface;
use Helioviewer\EventsApi\Utils\TimeRange;
use Helioviewer\EventsApi\Events\Event;
use Helioviewer\EventsApi\Events\Sources\JsonSource;
use Helioviewer\EventsApi\Exception\CoordinateResolutionException;
use Helioviewer\EventsApi\Exception\SourceException;
use Helioviewer\EventsApi\Exception\InvalidEventException;
use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;
use Helioviewer\EventsApi\Sentry\VoidClient as SentryVoidClient;
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
     * @param DistributionRepositoryInterface $distributionRepository Repository for distribution updates
     * @param JsonStorageInterface $json_storage Storage service for raw JSON data
     * @param JsonStorageInterface $failure_storage Storage service for failure data (non-sharded)
     * @param LoggerInterface|null $logger Logger for recording collection activities
     */
    public function __construct(
        private RepositoryInterface $repository,
        private RegionRepositoryInterface $regionRepository,
        private DistributionRepositoryInterface $distributionRepository,
        private JsonStorageInterface $json_storage,
        private JsonStorageInterface $failure_storage,
        private ?LoggerInterface $logger = null,
        private ?SentryClientInterface $sentry = null
    ) {
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
        $this->sentry = $sentry ?? new SentryVoidClient([]);
    }
    
    /**
     * Create a pre-configured collector with standard sources and processors
     *
     * This factory method creates a collector instance with all the standard
     * sources and processors configured as used in bin/collect.php
     *
     * @param RepositoryInterface $repository Repository for event persistence
     * @param RegionRepositoryInterface $regionRepository Repository for region persistence
     * @param DistributionRepositoryInterface $distributionRepository Repository for distribution updates
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
        DistributionRepositoryInterface $distributionRepository,
        JsonStorageInterface $json_storage,
        JsonStorageInterface $failure_storage,
        \Helioviewer\EventsApi\Utils\CachedHttpClient $httpClient,
        \Helioviewer\EventsApi\Jsoc\HarpService $harpService,
        \Helioviewer\EventsApi\Jsoc\NoaaService $noaaService,
        ?LoggerInterface $logger = null,
        ?SentryClientInterface $sentry = null
    ): self {
        // Create collector instance
        $collector = new self($repository, $regionRepository, $distributionRepository, $json_storage, $failure_storage, $logger, $sentry);
        
        // === SOURCES ===
        $collector->addSource('HEK', new HEKSource($httpClient));

        // Reuse the sentry client from the collector instance for all processors
        $sentryForProcessors = $collector->sentry;

        // === PROCESSORS ===
        // Specialized HEK processors
        $collector->addProcessor(new HEKARProcessor($logger, $sentryForProcessors));  // AR - Active Region
        $collector->addProcessor(new HEKCEProcessor($logger, $sentryForProcessors));  // CE - CME
        $collector->addProcessor(new HEKCHProcessor($logger, $sentryForProcessors));  // CH - Coronal Hole
        $collector->addProcessor(new HEKEFProcessor($logger, $sentryForProcessors));  // EF - Emerging Flux
        $collector->addProcessor(new HEKFIProcessor($logger, $sentryForProcessors));  // FI - Filament
        $collector->addProcessor(new HEKFlareProcessor($logger, $sentryForProcessors));  // FL - Flare (uses peak for coordinate_time)
        $collector->addProcessor(new HEKSGProcessor($logger, $sentryForProcessors));  // SG - Sigmoid

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
            $collector->addProcessor(new HEKEventTypeProcessor($eventType, $logger, $sentryForProcessors));
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
        $collector->addProcessor(new DonkiFlareProcessor($logger, $sentryForProcessors));
        $collector->addProcessor(new DonkiCmeProcessor($logger, $sentryForProcessors));

        // DAFF processor uses direct service integration (no resolvers)
        $daffProcessor = new DaffProcessor($harpService, $noaaService, $logger, $sentryForProcessors);
        $collector->addProcessor($daffProcessor);

        // ASSA processor with custom coordinate extraction
        $assaProcessor = new AssaProcessor($logger, $sentryForProcessors);
        $collector->addProcessor($assaProcessor);

        // FlareScoreboard processor reads coordinates directly from fields (no resolvers needed)
        $flareScoreboardProcessor = new FlareScoreboardProcessor($logger, $sentryForProcessors);
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
     * Get all registered processors.
     *
     * @return array<ProcessorInterface>
     */
    public function getProcessors(): array
    {
        return $this->processors;
    }

    /**
     * Process a single raw record: run it through the first matching processor,
     * upsert the event, write attached JSONs (sources/views/links), attach
     * regions, and optionally update distributions.
     *
     * Used by collect() for freshly-fetched records and by bin/reprocess.php
     * to replay stored raw records through the current processor code.
     *
     * @param array<string, mixed> $rawRecord  Raw source record
     * @param SourceInterface      $source     The source it came from
     * @param string               $pathPrefix Path prefix to prepend (e.g. "HEK")
     * @param bool                 $reprocess  When true, skip updating
     *                                         distributions AND skip rewriting
     *                                         sources/<uuid>.json (the input).
     *                                         Defaults to false (normal collect).
     * @return Event|null The saved Event, or null if no processor matched.
     * @throws InvalidEventException | CoordinateResolutionException
     *         Let the caller handle (write failure JSONs, log, etc.)
     */
    public function processRawRecord(
        array $rawRecord,
        SourceInterface $source,
        string $pathPrefix,
        bool $reprocess = false
    ): ?Event {
        $processor = null;
        foreach ($this->processors as $p) {
            if ($p->canProcess($source, $rawRecord)) {
                $processor = $p;
                break;
            }
        }
        if (!$processor) {
            return null;
        }

        // Process the raw record into an unpersisted Event model
        $event = $processor->process($rawRecord, $source);

        // Extract remote ID for deduplication and set it
        $event->remote_id = $source->getName() . ":" . $source->extractRawRecordId($rawRecord);

        if (empty($event->path)) {
            $event->path = $pathPrefix;
        } else {
            $event->path = $pathPrefix . '>>' . $event->path;
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
            // Check if time/path changed for distribution update
            $distributionChanged = (
                $existingEvent->start !== $event->start ||
                $existingEvent->end !== $event->end ||
                $existingEvent->path !== $event->path
            );

            // Remove old distribution counts if time/path changed
            if (!$reprocess && $distributionChanged) {
                $this->logger->debug("Distribution update needed: time/path changed");
                $this->distributionRepository->removeEvent($existingEvent);
            }

            // Update existing event with new data
            $existingEvent->fill($event->toArray());
            $savedEvent = $this->repository->save($existingEvent);

            // Add new distribution counts if time/path changed
            if (!$reprocess && $distributionChanged) {
                $this->distributionRepository->addEvent($savedEvent);
            }

            $action = $distributionChanged ? "Updated (dist changed)" : "Updated";
        } else {
            // Save new event
            $savedEvent = $this->repository->save($event);

            // Add to distributions
            if (!$reprocess) {
                $this->distributionRepository->addEvent($savedEvent);
            }

            $action = "Created";
        }

        $uuid = $savedEvent->id;

        // Save raw source data using sharded storage (skip in reprocess mode — it IS the input)
        if (!$reprocess) {
            $this->json_storage->storeById($uuid, 'sources', $rawRecord);
        }

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

        // Log the action; reprocess-mode callers can suppress if they want quieter output
        $this->logger->info("{$action} event: {$savedEvent->remote_id} | {$savedEvent->getUrl()}");

        return $savedEvent;
    }
    
    /**
     * Collect and process events from all registered sources within the specified time range.
     *
     * Processes the time range in chunks according to the specified interval (default: 1 day).
     * This allows efficient processing of large date ranges while managing memory usage and API calls.
     *
     * Memory optimization: Events are counted but not accumulated to prevent OOM on large ranges.
     *
     * @param TimeRange $range Time range for data collection
     * @param int $chunkInterval Number of days per processing chunk (default: 1)
     *
     * @return int Total number of events collected (not the events themselves to save memory)
     */
    public function collect(TimeRange $range, int $chunkInterval = 1): int
    {
        $totalEventCount = 0;

        // Process all registered sources
        $sourcesToProcess = $this->sources;

        // Process in chunks based on interval
        $chunks = $range->splitByInterval($chunkInterval);

        $collectionStartTime = microtime(true);

        foreach ($chunks as $index => $chunkRange) {
            $chunkStartTime = microtime(true);
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
                    $sourceStartTime = microtime(true);
                    $chunkEventCount = $this->collectFromSource($path, $source, $chunkRange);
                    $sourceEndTime = microtime(true);
                    $sourceDuration = round($sourceEndTime - $sourceStartTime, 2);
                    $avgPerEvent = $chunkEventCount > 0 ? round(($sourceDuration / $chunkEventCount) * 1000, 1) : 0;
                    $totalEventCount += $chunkEventCount;
                    $this->logger->info("Collected {$chunkEventCount} events in {$sourceDuration}s ({$avgPerEvent}ms/event)");

                    // Add delay after fetching each chunk's data (except for the last chunk)
                    // This prevents overwhelming APIs when fetching multiple chunks
                    if ($index < count($chunks) - 1) {
                        $sleepTime = mt_rand(500000, 1500000); // microseconds (0.5 to 1.5 seconds)
                        $this->logger->debug("Sleeping for " . round($sleepTime / 1000000, 2) . " seconds before next chunk");
                        usleep($sleepTime);
                    }
                } catch (SourceException $e) {
                    // One source dying must not stop the others. Log + report, then continue.
                    $this->logger->critical("Source exception: {$e->getSourcePath()} => {$e->getSourceName()}: {$e->getMessage()}");
                    $this->logger->debug("SourceException details: " . $e->getTraceAsString());
                    $this->sentry->setContext('SourceFailure', [
                        'source'     => $source->getName(),
                        'path'       => $path,
                        'chunk'      => $index + 1,
                        'date_range' => $chunkRange->getStartDate() . " to " . $chunkRange->getEndDate(),
                    ]);
                    $this->sentry->capture($e);
                } catch (\Exception $e) {
                    $this->logger->critical("Failed to collect: {$e->getMessage()}");
                    $this->logger->debug("Exception details: " . get_class($e) . " - " . $e->getTraceAsString());
                    $this->sentry->setContext('SourceFailure', [
                        'source'     => $source->getName(),
                        'path'       => $path,
                        'chunk'      => $index + 1,
                        'date_range' => $chunkRange->getStartDate() . " to " . $chunkRange->getEndDate(),
                    ]);
                    $this->sentry->capture($e);
                } finally {
                    // Remove the processor when done with this source
                    $this->logger->popProcessor();
                }
            }

            // Chunk timing summary
            $chunkEndTime = microtime(true);
            $chunkDuration = round($chunkEndTime - $chunkStartTime, 2);
            $elapsedTotal = round($chunkEndTime - $collectionStartTime, 2);
            $remainingChunks = count($chunks) - ($index + 1);
            $estimatedRemaining = $remainingChunks > 0 ? round(($elapsedTotal / ($index + 1)) * $remainingChunks, 0) : 0;
            $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 1);

            $this->logger->info("Chunk " . ($index + 1) . "/" . count($chunks) . " completed in {$chunkDuration}s | " .
                              "Total elapsed: {$elapsedTotal}s | " .
                              "ETA: {$estimatedRemaining}s | " .
                              "Memory: {$memoryUsage}MB | " .
                              "Events so far: {$totalEventCount}");

            // Force garbage collection after each chunk to free memory
            gc_collect_cycles();
        }

        // Final summary
        $totalDuration = round(microtime(true) - $collectionStartTime, 2);
        $avgPerEvent = $totalEventCount > 0 ? round(($totalDuration / $totalEventCount) * 1000, 1) : 0;
        $eventsPerSecond = $totalDuration > 0 ? round($totalEventCount / $totalDuration, 1) : 0;
        $this->logger->info("Collection finished | {$totalEventCount} events | {$totalDuration}s total | {$eventsPerSecond} events/s | {$avgPerEvent}ms/event");

        return $totalEventCount;
    }

    /**
     * Collect and process events from a specific data source object.
     *
     * @param string $path The path/identifier for this source
     * @param SourceInterface $source The source object to collect from
     * @param TimeRange $range Time range for data collection
     *
     * @return int Number of events successfully processed
     */
    private function collectFromSource(string $path, SourceInterface $source, TimeRange $range): int
    {
        $sourceName = $source->getName();

        // Let exceptions bubble up - they'll be caught in the collect() method
        $rawData = $source->fetchRawData($range);

        $totalRawRecords = count($rawData);
        $this->logger->info("Found {$totalRawRecords} raw event records");

        $processedCount = 0;
        $seenRemoteIds = [];  // Track remote_ids within this batch
        $duplicateCount = 0;

        foreach ($rawData as $index => $rawRecord) {

            $processorFound = false;

            // Find matching processor (for failure-handling context and dedup tracking)
            $matchingProcessor = null;
            foreach ($this->processors as $processor) {
                if ($processor->canProcess($source, $rawRecord)) {
                    $matchingProcessor = $processor;
                    break;
                }
            }

            if ($matchingProcessor !== null) {
                $processorFound = true;
                $processorClass = get_class($matchingProcessor);

                try {
                    // Batch-level duplicate check (peek at the remote_id without running the processor twice)
                    $previewRemoteId = $source->getName() . ":" . $source->extractRawRecordId($rawRecord);
                    if (isset($seenRemoteIds[$previewRemoteId])) {
                        $duplicateCount++;
                        $firstIndex = $seenRemoteIds[$previewRemoteId];
                        $this->logger->warning("DUPLICATE remote_id in batch: {$previewRemoteId} (first at index {$firstIndex}, duplicate at index {$index})");
                    } else {
                        $seenRemoteIds[$previewRemoteId] = $index;
                    }

                    // Full processing + persistence (collect always updates distributions)
                    $savedEvent = $this->processRawRecord($rawRecord, $source, $path, false);
                    if ($savedEvent !== null) {
                        $processedCount++;
                    }

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

                    // Report to Sentry with context pointing at the stored failure record
                    $this->sentry->setContext('EventFailure', [
                        'source' => $sourceName,
                        'failure_url' => $failureURL,
                        'exception' => get_class($e),
                    ]);
                    $this->sentry->capture($e);
                }
            }

            if (!$processorFound) {
                $remoteId = $source->extractRawRecordId($rawRecord);
                $this->logger->warning("No processor found for event | source: {$path} | remote_id: {$remoteId}");
                $this->sentry->setContext('NoProcessor', [
                    'source' => $sourceName,
                    'path' => $path,
                    'remote_id' => $remoteId,
                ]);
                $this->sentry->message("No processor found for event: {$path} / {$remoteId}");
            }
        }

        // Free memory from raw data
        unset($rawData);

        // Log duplicate summary if any duplicates found
        if ($duplicateCount > 0) {
            $uniqueCount = count($seenRemoteIds);
            $this->logger->error("BATCH DUPLICATE SUMMARY: {$duplicateCount} duplicates found out of {$totalRawRecords} records ({$uniqueCount} unique remote_ids)");
        }

        return $processedCount;
    }
}
