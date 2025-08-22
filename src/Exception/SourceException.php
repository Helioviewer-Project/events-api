<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Exception;

/**
 * Source Exception
 * 
 * Thrown when a source fails to fetch or process data.
 * This exception wraps any errors that occur during source data fetching.
 * 
 * @package Helioviewer\EventsApi\Exception
 */
class SourceException extends \Exception
{
    private string $sourceName;
    private string $sourcePath;
    
    /**
     * Constructor
     * 
     * @param string $message The exception message
     * @param string $sourceName The name of the source that failed
     * @param string $sourcePath The path of the source
     * @param int $code The exception code
     * @param ?\Throwable $previous The previous throwable for chaining
     */
    public function __construct(
        string $message, 
        string $sourceName = '', 
        string $sourcePath = '', 
        int $code = 0, 
        ?\Throwable $previous = null
    ) {
        $this->sourceName = $sourceName;
        $this->sourcePath = $sourcePath;
        parent::__construct($message, $code, $previous);
    }
    
    public function getSourceName(): string
    {
        return $this->sourceName;
    }
    
    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }
}