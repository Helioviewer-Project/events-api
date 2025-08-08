<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Processors;

use Helioviewer\EventsApi\Collector\Coordinate\ResolverInterface;
use Helioviewer\EventsApi\Collector\Coordinate\CoordinateException;
use Helioviewer\EventsApi\Models\Event;
use Helioviewer\EventsApi\Sources\SourceInterface;

/**
 * Abstract Event Processor
 *
 * Base class for all event processors that provides coordinate resolution functionality.
 * Processors can be configured with different coordinate resolution strategies through
 * dependency injection.
 *
 * @package Helioviewer\EventsApi\Processors
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
abstract class AbstractProcessor implements ProcessorInterface
{
    /** @var ResolverInterface[] */
    protected array $coordinateResolvers = [];

    /**
     * Add a coordinate resolver to this processor.
     *
     * @param ResolverInterface $resolver The coordinate resolver to add
     * @return self For method chaining
     */
    public function addCoordinateResolver(ResolverInterface $resolver): self
    {
        $this->coordinateResolvers[] = $resolver;
        return $this;
    }

    /**
     * Get coordinates from raw record using coordinate resolvers.
     *
     * @param array $rawRecord The raw event data
     * @return array|null Array with coordinate data or null if no resolver succeeds
     * @throws CoordinateException If no resolver can resolve coordinates
     */
    protected function getCoordinates(array $rawRecord): ?array
    {
        // Try each resolver in order until one succeeds
        foreach ($this->coordinateResolvers as $resolver) {
            if ($resolver->canResolve($rawRecord)) {
                $result = $resolver->resolve($rawRecord);
                // Return first non-null result
                if ($result !== null) {
                    return $result;
                }
            }
        }

        // If we get here, no resolver could resolve coordinates
        throw new CoordinateException("Failed to resolve coordinates from raw record data using all available resolvers");
    }

    /**
     * Template method implementation of process().
     * 
     * This method defines the processing workflow:
     * 1. Get timeline information (start, peak, end)
     * 2. Get coordinates from resolvers
     * 3. Build base event data
     * 4. Let subclasses calculate remaining fields
     * 5. Return filled Event object
     *
     * @param array $rawRecord Raw event data from data source
     * @param SourceInterface $source The source object providing context and metadata
     * @return Event Processed and validated Event model instance
     */
    public function process(array $rawRecord, SourceInterface $source): Event
    {
        // Step 1: Get timeline information
        $timeline = $this->getTimeline($rawRecord);
        
        // Step 2: Get coordinates
        $coordinates = $this->getCoordinates($rawRecord);
        
        // Step 3: Build base event data with coordinates and timeline
        // Handle coordinate time calculation
        $coordinateTime = isset($coordinates['locationTime']) && $coordinates['locationTime'] 
            ? strtotime($coordinates['locationTime']) 
            : $timeline['start'];
            
        $baseEventData = [
            'start' => $timeline['start'],
            'peak' => $timeline['peak'],
            'end' => $timeline['end'],
            'coordinate_time' => $coordinateTime,
            'hv_hpc_x' => $coordinates['latitude'] ?? 0.0,
            'hv_hpc_y' => $coordinates['longitude'] ?? 0.0,
            'region' => $coordinates['region'] ?? null,
            'coordinate_source' => $coordinates['source'] ?? null,
        ];
        
        // Step 4: Let subclass calculate the rest of the fields
        $additionalData = $this->calculateRest($rawRecord, $baseEventData, $source);
        
        // Step 5: Merge base data with additional data
        $eventData = array_merge($baseEventData, $additionalData);
        
        // Step 6: Create and fill Event object
        $event = new Event();
        $event->fill($eventData);
        
        // Step 7: Handle legacy views and links separately
        if (isset($additionalData['legacy_views'])) {
            $event->legacy_views = $additionalData['legacy_views'];
        }
        if (isset($additionalData['legacy_links'])) {
            $event->legacy_links = $additionalData['legacy_links'];
        }
        
        return $event;
    }

    /**
     * Get timeline information (start, peak, end times) from raw record.
     * Must be implemented by subclasses to extract timing from their specific data format.
     *
     * @param array $rawRecord The raw event data
     * @return array Array with keys: start, peak, end (as timestamps)
     */
    abstract protected function getTimeline(array $rawRecord): array;

    /**
     * Calculate remaining event fields specific to the processor type.
     * This method must be implemented by subclasses.
     *
     * @param array $rawRecord The raw event data
     * @param array $eventData The already calculated base event data
     * @param SourceInterface $source The source object
     * @return array Additional event data fields
     */
    abstract protected function calculateRest(array $rawRecord, array $eventData, SourceInterface $source): array;

    /**
     * Create labels for events to match event interface format.
     * 
     * @param array $rawRecord The raw record
     * @param string $modelName The model name (dataset)
     * @param int|string|null $regionId The region ID if available
     * @param string|null $source The coordinate source
     * @return array Array with 'label' and 'short_label'
     */
    protected function createLabels(array $rawRecord, string $modelName, int|string|null $regionId, ?string $source): array
    {
        // Default implementation - can be overridden by subclasses
        return [
            'label' => $modelName,
            'short_label' => $modelName
        ];
    }
}
