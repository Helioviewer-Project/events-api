<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Exception;

/**
 * Coordinate Resolution Exception
 * 
 * Thrown when coordinate resolution or transformation fails for an event.
 * This exception indicates that an event should be skipped due to invalid,
 * missing, or untransformable coordinate data.
 * 
 * Common scenarios:
 * - CME events that should be ignored (IgnoreCme from translator)
 * - Missing coordinate data in source records
 * - Failed coordinate system transformations
 * - Invalid coordinate values that cannot be processed
 * 
 * @package Helioviewer\EventsApi\Exception
 */
class CoordinateResolutionException extends \Exception
{
    /**
     * Constructor
     * 
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous The previous throwable for chaining
     */
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}