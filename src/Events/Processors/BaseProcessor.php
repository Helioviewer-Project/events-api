<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Events\Processors;

use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;
use Helioviewer\EventsApi\Sentry\VoidClient as SentryVoidClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Abstract base class for all event processors.
 *
 * Holds cross-cutting dependencies (logger, Sentry client) that every
 * processor needs access to. Concrete subclasses should call
 * parent::__construct($logger, $sentry) to wire them up.
 *
 * @package Helioviewer\EventsApi\Events\Processors
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
abstract class BaseProcessor implements ProcessorInterface
{
    protected LoggerInterface $logger;
    protected SentryClientInterface $sentry;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?SentryClientInterface $sentry = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->sentry = $sentry ?? new SentryVoidClient([]);
    }
}
