<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Processors;

use Helioviewer\EventsApi\Models\Event;
use Helioviewer\EventsApi\Sources\SourceInterface;

/**
 * Event Processor Interface
 *
 * Defines the contract for all event processors that transform raw data
 * from various sources into standardized Event model objects. Processors
 * are responsible for data validation, normalization, coordinate
 * transformations, and mapping source-specific fields to the unified
 * Event schema.
 *
 * Implementations must:
 * - Handle source-specific data formats and structures
 * - Perform necessary data validation and sanitization
 * - Apply coordinate system transformations as needed
 * - Map raw fields to standardized Event model properties
 * - Provide capability checking for different data sources
 *
 * @package Helioviewer\EventsApi\Processors
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
interface EventProcessorInterface
{
    /**
     * Transform raw event data into a standardized Event model.
     *
     * This method performs the core data transformation from source-specific
     * raw data formats into the unified Event model structure. It should
     * handle data validation, field mapping, coordinate transformations,
     * and any other processing required to create a valid Event object.
     *
     * @param array<string, mixed> $rawRecord Raw event data from data source
     * @param SourceInterface $source The source object providing context and metadata
     *
     * @return Event Processed and validated Event model instance
     *
     * @throws \InvalidArgumentException If raw data is invalid or incomplete
     * @throws \RuntimeException If processing fails due to system errors
     */
    public function process(array $rawRecord, SourceInterface $source): Event;

    /**
     * Determine if this processor can handle the given source and record type.
     *
     * This method allows the system to automatically select the appropriate
     * processor for each raw record based on the source object and data
     * structure. It should perform lightweight checks to determine
     * compatibility without performing full processing.
     *
     * @param SourceInterface $source The source object
     * @param array<string, mixed> $rawRecord Raw event data to check
     *
     * @return bool True if this processor can handle the data, false otherwise
     */
    public function canProcess(SourceInterface $source, array $rawRecord): bool;
}