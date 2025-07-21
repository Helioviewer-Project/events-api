<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Sources;

use Helioviewer\EventsApi\Contracts\SourceInterface;

/**
 * Abstract base class for solar event data sources
 * 
 * Provides common functionality for event sources including path management.
 * All concrete source classes should extend this class.
 */
abstract class Source implements SourceInterface
{
    // Source ID constants
    public const CCMC = 1;
    public const HEK = 2;
    public const WSA = 3;
    public const RHESSI = 4;

    /**
     * Unique identifier path for this source
     */
    protected string $path;

    /**
     * Create a new source instance
     * 
     * @param string $path Unique identifier path for this source
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Get the source identifier path
     * 
     * @return string The unique identifier path for this source
     */
    public function getPath(): string
    {
        return $this->path;
    }
}
