<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Exception;

/**
 * Invalid Event Exception
 * 
 * Thrown when an event cannot be processed due to invalid or missing data.
 * This typically occurs when extractRawRecordId fails or required data is malformed.
 * 
 * @package Helioviewer\EventsApi\Exception
 */
class InvalidEventException extends \Exception
{
    private string $sourceName;
    private array $rawRecord;
    
    /**
     * Constructor
     * 
     * @param string $message The exception message
     * @param string $sourceName The name of the source
     * @param array $rawRecord The raw record data that caused the issue
     * @param int $code The exception code
     * @param ?\Throwable $previous The previous throwable for chaining
     */
    public function __construct(
        string $message, 
        string $sourceName = '', 
        array $rawRecord = [], 
        int $code = 0, 
        ?\Throwable $previous = null
    ) {
        $this->sourceName = $sourceName;
        $this->rawRecord = $rawRecord;
        parent::__construct($message, $code, $previous);
    }
    
    public function getSourceName(): string
    {
        return $this->sourceName;
    }
    
    public function getRawRecord(): array
    {
        return $this->rawRecord;
    }
}