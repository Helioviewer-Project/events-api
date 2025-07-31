<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Services;

use Helioviewer\EventsApi\Sources\SourceInterface;
use Helioviewer\EventsApi\Processors\EventProcessorInterface;
use Helioviewer\EventsApi\Repositories\EventRepositoryInterface;
use Helioviewer\EventsApi\Utils\TimeRange;
use Helioviewer\EventsApi\Models\Event;
use Helioviewer\EventsApi\Sources\JsonSource;

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
 * @package Helioviewer\EventsApi\Services
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class EventCollector
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
     * @var array<EventProcessorInterface>
     */
    private array $processors = [];

    /**
     * Construct a new EventCollector instance.
     *
     * @param EventRepositoryInterface $repository Repository for event persistence
     */
    public function __construct(
        private EventRepositoryInterface $repository
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
     * @param EventProcessorInterface $processor The event processor to register
     *
     * @return void
     */
    public function addProcessor(EventProcessorInterface $processor): void
    {
        $this->processors[] = $processor;
    }
    
    /**
     * Collect and process events from a specific data source object.
     *
     * Fetches raw data from the provided source within the given time range,
     * processes each record through registered processors, and returns the
     * resulting Event objects. Provides console output for monitoring progress.
     *
     * @param string $path The path/identifier for this source
     * @param SourceInterface $source The source object to collect from
     * @param TimeRange $range Time range for data collection
     *
     * @return array<Event> Array of processed Event objects
     */
    public function collectEvents(string $path, SourceInterface $source, TimeRange $range): array
    {
        $sourceName = $source->getName();
        $rawData = $source->fetchRawData($range);
        $events = [];
        
        echo "Processing " . count($rawData) . " raw records from {$sourceName}\n";
        
        foreach ($rawData as $rawRecord) {
            foreach ($this->processors as $processor) {
                if ($processor->canProcess($source, $rawRecord)) {
                    try {
                        // Process the raw record into an unpersisted Event model
                        $event = $processor->process($rawRecord, $source);

                        // Extract and set the remote ID for deduplication
                        $event->remote_id = $source->getName() . ":" . $source->extractRawRecordId($rawRecord);
                        
                        // Build hierarchical path: collector path + processor path (if any)
                        $event->path = empty($event->path) ? $path : $path . '>>' . $event->path;

                        // Handle upsert logic: find existing event or create new one
                        $existingEvent = $this->repository->findByRemoteId($event->remote_id);
                        
                        if ($existingEvent) {
                            // Update existing event with new data
                            $existingEvent->fill($event->getAttributes());
                            $savedEvent = $this->repository->save($existingEvent);
                            echo "Updated existing event: " . $savedEvent->remote_id . "\n";
                        } else {
                            // Save new event
                            $savedEvent = $this->repository->save($event);
                            echo "Created new event: " . $savedEvent->remote_id . "\n";
                        }
                        
                        $events[] = $savedEvent;
                        
                        // Log processing progress with event details
                        $eventTimeRange = TimeRange::fromEvent($savedEvent);
                        echo "Event duration: " . $eventTimeRange->getDuration() . "s\n";
                        
                        break; // First matching processor wins
                    } catch (\Exception $e) {
                        error_log("Failed to process event from {$sourceName}: " . $e->getMessage());
                    }
                }
            }
        }
        
        echo "Successfully processed " . count($events) . " events from {$sourceName}\n";
        return $events;
    }
    
    /**
     * Collect and process events from all registered data sources.
     *
     * Iterates through all registered sources and collects events within
     * the specified time range. Continues processing even if individual
     * sources fail, logging errors for failed sources.
     *
     * @param TimeRange $range Time range for data collection
     *
     * @return array<Event> Combined array of all processed Event objects
     */
    public function collectAllEvents(TimeRange $range): array
    {
        $allEvents = [];
        
        foreach ($this->sources as $path => $source) {
            try {
                $events = $this->collectEvents($path, $source, $range);
                $allEvents = array_merge($allEvents, $events);
            } catch (\Exception $e) {
                error_log("Failed to collect from {$source->getName()}: " . $e->getMessage());
            }
        }
        
        return $allEvents;
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
