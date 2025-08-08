<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Collector\Coordinate;

/**
 * Coordinate Resolver Interface
 *
 * Defines the contract for extracting coordinate information from raw event records.
 * Implementations can resolve coordinates through different strategies like reading
 * from raw record fields or calling external services.
 *
 * @package Helioviewer\EventsApi\Collector\Coordinate
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
interface ResolverInterface
{
    /**
     * Resolve coordinates from raw record data.
     *
     * @param array $rawRecord The raw event data
     *
     * @return array|null Array with coordinate data or null if resolution fails
     */
    public function resolve(array $rawRecord): ?array;
    
    /**
     * Check if this resolver can process the given raw record.
     *
     * @param array $rawRecord The raw event data
     *
     * @return bool True if this resolver can handle the record
     */
    public function canResolve(array $rawRecord): bool;
}