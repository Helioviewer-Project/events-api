<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;
use Helioviewer\EventsApi\Sentry\VoidClient as SentryVoidClient;
use Psr\Log\LoggerInterface;

/**
 * Coordinator decorator: tries the primary, falls back to the backup.
 *
 * A connection or error response marks the primary down for the rest of the
 * request, so later calls go straight to the backup and Sentry fires once per
 * tier. Failure of both rethrows — callers decide what an outage means.
 *
 * Note the backup has no /hgc2hpc endpoint; Carrington calls effectively ride
 * the primary only.
 *
 * @package Helioviewer\EventsApi\Coordinator
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class FailoverCoordinator implements CoordinatorInterface
{
    private CoordinatorInterface $primary;
    private CoordinatorInterface $backup;
    private ?LoggerInterface $logger;
    private SentryClientInterface $sentry;
    private bool $primaryFailed = false;

    /**
     * @param CoordinatorInterface $primary Primary coordinator
     * @param CoordinatorInterface $backup Backup coordinator
     * @param LoggerInterface|null $logger Optional logger
     * @param SentryClientInterface|null $sentry Optional Sentry client
     */
    public function __construct(
        CoordinatorInterface $primary,
        CoordinatorInterface $backup,
        ?LoggerInterface $logger = null,
        ?SentryClientInterface $sentry = null
    ) {
        $this->primary = $primary;
        $this->backup = $backup;
        $this->logger = $logger;
        $this->sentry = $sentry ?? new SentryVoidClient([]);
    }

    /**
     * @param array $coordinateArray Coords with lat, lon, coordinate_time
     * @param int|string $targetTimestamp Target time
     * @return array
     * @throws CoordinatorException
     */
    public function stonyhurstToHelioprojectiveBatch(array $coordinateArray, $targetTimestamp): array
    {
        return $this->attempt(
            fn(CoordinatorInterface $c) => $c->stonyhurstToHelioprojectiveBatch($coordinateArray, $targetTimestamp),
            'Stonyhurst'
        );
    }

    /**
     * @param array $coordinateArray Coords with lat, lon, coordinate_time
     * @param int|string $targetTimestamp Target time
     * @return array
     * @throws CoordinatorException
     */
    public function carringtonToHelioprojectiveBatch(array $coordinateArray, $targetTimestamp): array
    {
        return $this->attempt(
            fn(CoordinatorInterface $c) => $c->carringtonToHelioprojectiveBatch($coordinateArray, $targetTimestamp),
            'Carrington'
        );
    }

    /**
     * @param array $coordinateArray Coords with x, y, coordinate_time
     * @param int|string $targetTimestamp Target time
     * @return array
     * @throws CoordinatorException
     */
    public function helioprojectiveToHelioprojectiveBatch(array $coordinateArray, $targetTimestamp): array
    {
        return $this->attempt(
            fn(CoordinatorInterface $c) => $c->helioprojectiveToHelioprojectiveBatch($coordinateArray, $targetTimestamp),
            'Helioprojective'
        );
    }

    /**
     * @param callable $call Receives a coordinator, returns its result
     * @param string $system Coordinate system name for logging
     * @return array
     * @throws CoordinatorException When both tiers fail
     */
    private function attempt(callable $call, string $system): array
    {
        // Once the primary has failed in this request, stop paying its timeout.
        if (!$this->primaryFailed) {
            try {
                return $call($this->primary);
            } catch (CoordinatorException $e) {
                $this->primaryFailed = true;
                $reason = $e instanceof CoordinatorConnectionException ? 'unreachable' : 'error_response';
                if ($this->logger) {
                    $this->logger->warning("FailoverCoordinator | {$system} | Primary {$reason}: " . $e->getMessage() . " | Falling back to backup");
                }
                $this->sentry->setContext('Coordinator', [
                    'system' => $system,
                    'tier' => 'primary',
                    'reason' => $reason,
                ]);
                $this->sentry->capture($e);
            }
        }

        // Backup is the last resort; if it fails too the caller has to deal with it.
        try {
            return $call($this->backup);
        } catch (CoordinatorException $e) {
            if ($this->logger) {
                $this->logger->error("FailoverCoordinator | {$system} | Backup also failed: " . $e->getMessage());
            }
            $this->sentry->setContext('Coordinator', [
                'system' => $system,
                'tier' => 'backup',
                'primary_failed' => $this->primaryFailed,
            ]);
            $this->sentry->capture($e);
            throw $e;
        }
    }
}
