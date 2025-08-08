<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Collector;

use Helioviewer\EventsApi\Sources\SourceInterface;
use Helioviewer\EventsApi\Processors\ProcessorInterface;
use Helioviewer\EventsApi\Repositories\EventRepositoryInterface;
use Helioviewer\EventsApi\JsonStorage\JsonStorageInterface;
use Helioviewer\EventsApi\Utils\TimeRange;
use Helioviewer\EventsApi\Models\Event;
use Helioviewer\EventsApi\Sources\JsonSource;
use Helioviewer\EventsApi\Collector\Coordinate\CoordinateException;

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
     * @param EventRepositoryInterface $repository Repository for event persistence
     * @param JsonStorageInterface $json_storage Storage service for raw JSON data
     */
    public function __construct(
        private EventRepositoryInterface $repository,
        private JsonStorageInterface $json_storage
    ) {
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
     * Collect and process events from sources within the specified time range.
     *
     * If source is null, processes all registered sources. If the time range 
     * spans multiple days, it processes each day separately to optimize 
     * memory usage and API calls.
     *
     * @param TimeRange $range Time range for data collection
     * @param SourceInterface|null $source Specific source to collect from, or null for all sources
     *
     * @return array<Event> Array of processed Event objects
     */
    public function collect(TimeRange $range, ?SourceInterface $source = null): array
    {
        $allEvents = [];
        
        // Determine which sources to process
        $sourcesToProcess = [];
        if ($source !== null) {
            // Find the path for this specific source
            $sourcePath = null;
            foreach ($this->sources as $path => $registeredSource) {
                if ($registeredSource === $source) {
                    $sourcePath = $path;
                    break;
                }
            }
            if ($sourcePath === null) {
                throw new \InvalidArgumentException("Source not registered in collector");
            }
            $sourcesToProcess[$sourcePath] = $source;
        } else {
            // Process all registered sources
            $sourcesToProcess = $this->sources;
        }
        
        // Display sources to be processed
        echo "Sources to process:\n";
        foreach ($sourcesToProcess as $path => $sourceToProcess) {
            echo "  {$path} >> {$sourceToProcess->getName()}\n";
        }
        echo "\n";
        
        // Process day by day (even if single day)
        $dailyChunks = $range->splitByDays();
        echo "Time range spans " . count($dailyChunks) . " days. Processing day by day:\n";
        
        foreach ($dailyChunks as $index => $dailyRange) {
            echo "\n=== Processing Day " . ($index + 1) . "/" . count($dailyChunks) . " ===\n";
            echo "Date: " . $dailyRange->getStartDate() . " (" . date('Y-m-d H:i:s', $dailyRange->start) . " to " . date('Y-m-d H:i:s', $dailyRange->end) . ")\n\n";
            
            foreach ($sourcesToProcess as $path => $sourceToProcess) {
                try {
                    $events = $this->collectFromSource($path, $sourceToProcess, $dailyRange);
                    $allEvents = array_merge($allEvents, $events);
                } catch (\Exception $e) {
                    error_log("Failed to collect from {$sourceToProcess->getName()} for {$dailyRange->getStartDate()}: " . $e->getMessage());
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
        $rawData = $source->fetchRawData($range);
        $events = [];
        
        $totalRawRecords = count($rawData);
        echo "Processing {$totalRawRecords} raw records from {$sourceName}\n";
        
        $processedCount = 0;
        foreach ($rawData as $index => $rawRecord) {
            echo "\n" . str_repeat('=', 80) . "\n";
            echo "RAW RECORD [{$index}] from {$sourceName}:\n";
            echo str_repeat('-', 80) . "\n";
            echo json_encode($rawRecord, JSON_PRETTY_PRINT) . "\n";
            echo str_repeat('-', 80) . "\n";
            
            foreach ($this->processors as $processor) {
                if ($processor->canProcess($source, $rawRecord)) {
                    $processorClass = get_class($processor);
                    echo "PROCESSOR: {$processorClass} matched this record\n";
                    
                    try {
                        // Process the raw record into an unpersisted Event model
                        $event = $processor->process($rawRecord, $source);

                        // Extract and set the remote ID for deduplication
                        $event->remote_id = $source->getName() . ":" . $source->extractRawRecordId($rawRecord);
                        if(empty($event->path)) {
                            $event->path = $path;
                        } else {
                            $event->path = $path . '||' .$event->path;
                        }
                        
                        // Log the processed coordinates
                        echo "PROCESSED COORDINATES:\n";
                        echo "  - HV_HPC_X (latitude): {$event->hv_hpc_x}\n";
                        echo "  - HV_HPC_Y (longitude): {$event->hv_hpc_y}\n";
                        echo "  - Coordinate Time: " . date('Y-m-d H:i:s', $event->coordinate_time) . " (timestamp: {$event->coordinate_time})\n";
                        echo "  - Label: {$event->label}\n";
                        echo "  - Short Label: {$event->short_label}\n";
                        
                        // Store views and links temporarily before DB save
                        $tempViews = $event->legacy_views;
                        $tempLinks = $event->legacy_links;
                        unset($event->legacy_views, $event->legacy_links);

                        // Handle upsert logic: find existing event or create new one
                        $existingEvent = $this->repository->findByRemoteId($event->remote_id);
                        
                        if ($existingEvent) {
                            // Update existing event with new data
                            $existingEvent->fill($event->getAttributes());
                            $savedEvent = $this->repository->save($existingEvent);
                            echo "RESULT: Updated existing event: " . $savedEvent->remote_id . "\n";
                        } else {
                            // Save new event
                            $savedEvent = $this->repository->save($event);
                            echo "RESULT: Created new event: " . $savedEvent->remote_id . "\n";
                        }
                        
                        // Now save JSON files using the UUID after successful DB save
                        $uuid = $savedEvent->id;
                        
                        // Save raw source data
                        $this->json_storage->store("/u/apps/data/sources/{$uuid}.json", $rawRecord);
                        
                        // Save views data
                        $this->json_storage->store("/u/apps/data/views/{$uuid}.json", $tempViews);
                        
                        // Save links data
                        $this->json_storage->store("/u/apps/data/links/{$uuid}.json", $tempLinks);
                        
                        $events[] = $savedEvent;

                        $processedCount++;
                        
                        // Log processing progress with event details
                        $eventTimeRange = TimeRange::fromEvent($savedEvent);
                        echo "Event duration: " . $eventTimeRange->getDuration() . "s\n";
                        echo "Progress: {$processedCount} events processed\n";
                        echo str_repeat('=', 80) . "\n\n";
                        
                        break; // First matching processor wins
                    } catch (CoordinateException $e) {
                        echo "COORDINATE ERROR: " . $e->getMessage() . "\n";
                        echo "Skipping event due to coordinate resolution failure.\n";
                        error_log("Failed to resolve coordinates for event from {$sourceName}: " . $e->getMessage());
                    } catch (\Exception $e) {
                        echo "ERROR: Failed to process event: " . $e->getMessage() . "\n";
                        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
                        error_log("Failed to process event from {$sourceName}: " . $e->getMessage());
                    }
                }
            }
        }
        
        echo "Successfully processed " . count($events) . " events from {$sourceName}\n";
        return $events;
    }
    
    
    /**
     * Get source information as formatted string.
     *
     * @return string Formatted source information
     */
    public function getSourceInfo(): string
    {
        $output = "=== Registered Data Sources ===\n";
        
        if (empty($this->sources)) {
            return $output . "No sources registered.\n";
        }
        
        foreach ($this->sources as $path => $source) {
            $output .= "Path: {$path}\n";
            $output .= "Source: {$source->getName()}\n";
            $output .= str_repeat('-', 40) . "\n";
        }
        
        $output .= "Total sources: " . count($this->sources) . "\n";
        $output .= "Total processors: " . count($this->processors) . "\n";
        
        return $output;
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
