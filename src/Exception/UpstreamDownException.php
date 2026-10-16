<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Exception;

/**
 * CCMC or JSOC did not answer while collecting. A CLI collect stops on it
 * (and says where to resume); the scheduler skips that source for the run.
 *
 * @package Helioviewer\EventsApi\Exception
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class UpstreamDownException extends \RuntimeException
{
    /**
     * @param string          $message    What was down and what failed
     * @param string|null     $resumeFrom First date (Y-m-d) still to collect, once known
     * @param \Throwable|null $previous   The underlying error
     */
    public function __construct(string $message, public readonly ?string $resumeFrom = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
