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
                    $this->logger->critical("Source failed: {$e->getSourcePath()} => {$e->getSourceName()}: {$e->getMessage()}");
                    pre($e);
                } catch (\Exception $e) {
                    $this->logger->critical("Failed to collect: {$e->getMessage()}");
                    pre($e);
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
 
            
            foreach ($this->processors as $processor) {
                if ($processor->canProcess($source, $rawRecord)) {
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
                        
                        
                        // Store views, links, and region info temporarily before DB save
                        $tempViews = $event->legacy_views;
                        $tempLinks = $event->legacy_links;
                        $tempRegionInfo = $event->region_info ?? null;
                        $tempRegionsInfo = $event->regions_info ?? null;
                        unset($event->legacy_views, $event->legacy_links, $event->region_info, $event->regions_info);

                        // Handle upsert logic: find existing event or create new one
                        $existingEvent = $this->repository->findByRemoteId($event->remote_id);
                        
                        if ($existingEvent) {
                            // Update existing event with new data
                            $existingEvent->fill($event->getAttributes());
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
                        $this->json_storage->storeById($uuid, 'views', $tempViews);
                        
                        // Save links data using sharded storage
                        $this->json_storage->storeById($uuid, 'links', $tempLinks);
                        
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
                        
                        // Get API URL for event view links
                        $apiUrl = rtrim($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', '/');
                        $eventViewUrl = $apiUrl . "/api/v2/events/{$savedEvent->id}";
                        
                        // Log processing progress with event details and view link
                        $this->logger->info("{$action} event: {$savedEvent->remote_id} | {$eventViewUrl}");
                        
                    } catch (\Exception $e) {

                        $failureId = hash('sha256', json_encode($rawRecord)) . '.json';
                        
                        // Set path based on exception type
                        $failurePath = match (true) {
                            $e instanceof InvalidEventException => "failures/invalid_events/{$sourceName}",
                            $e instanceof CoordinateResolutionException => "failures/coordinate_errors/{$sourceName}",
                            default => "failures/general_errors/{$sourceName}"
                        };
                        
                        // Get exception type short name
                        $exceptionType = match (true) {
                            $e instanceof InvalidEventException => 'InvalidEventException',
                            $e instanceof CoordinateResolutionException => 'CoordinateResolutionException',
                            default => 'Exception'
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
                        $storedPath = $this->failure_storage->storeById(str_replace('.json', '', $failureId), $failurePath, $failureData);
                        
                        // Convert file path to URL by replacing base path with API URL
                        $apiUrl = rtrim($_ENV['APIURL'] ?? 'https://events.helioviewer.org/', '/');
                        $basePath = '/u/apps/data';
                        $fullUrl = str_replace($basePath, $apiUrl . '/storage', $storedPath);
                        
                        // Log with appropriate level based on exception type
                        if ($e instanceof CoordinateResolutionException) {
                            $this->logger->warning("{$exceptionType} | {$e->getMessage()} | {$fullUrl}");
                        } else {
                            $this->logger->error("{$exceptionType} | {$e->getMessage()} | {$fullUrl}");
                        }
                        
                    }

                    // First matching processor wins - stop processing other processors
                    break;
                }
            }
        }
        
        // $this->logger->info("Successfully processed " . count($events) . " events from {$sourceName}");
        return $events;
    }

    /**
     * Get comprehensive statistics about the event collection system.
     *
     * Provides information about registered sources, processors, and
     * database statistics for monitoring and debugging purposes.
     *
     * @return array<string, mixed> Statistics array containing:
     *   - total_sources: Number of registered sources
     *   - total_processors: Number of registered processors
     *   - sources: Array of source names
     *   - ccmc_events: Count of CCMC events in database
     *   - recent_events: Count of recent events (last 10)
     */
    public function getStats(): array
    {
        return [
            'total_sources' => count($this->sources),
            'total_processors' => count($this->processors),
            'sources' => array_keys($this->sources),
            'ccmc_events' => $this->repository->countBySource('CCMC'),
            'recent_events' => count($this->repository->getRecent(10))
        ];
    }
}
