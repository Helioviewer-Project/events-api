<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Collector\Coordinate;

/**
 * Coordinate Exception
 *
 * Thrown when coordinate resolution fails for all available resolvers.
 * Indicates that no resolver could successfully extract coordinates from the raw record.
 *
 * @package Helioviewer\EventsApi\Collector\Coordinate
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class CoordinateException extends \Exception
{
    public function __construct(string $message = "Failed to resolve coordinates from all available resolvers", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}