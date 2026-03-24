<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

/**
 * Exception for coordinator connection-level failures (timeout, refused, DNS).
 * Signals that the coordinator service is unreachable, not just returning errors.
 */
class CoordinatorConnectionException extends CoordinatorException
{
}
