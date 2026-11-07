<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator;

use Helioviewer\EventsApi\Sentry\ClientInterface as SentryClientInterface;
use Helioviewer\EventsApi\Sentry\VoidClient as SentryVoidClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Log\LoggerInterface;

/**
 * Coordinator decorator: tries the primary, falls back to the backup.
 *
 * A connection or error response marks the primary down for the rest of the
 * request, so later calls go straight to the backup and Sentry fires once per
 * tier. Failure of both rethrows — callers decide what an outage means.
 *
 * batchAsync() sends to the primary in parallel, but a call that falls back
 * goes to the backup synchronously, one at a time: the in-stack backup shares
 * the API host's CPU.
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
     * @param string $system carrington, stonyhurst, or helioprojective
     * @param array $coordinateArray Coordinates keyed by event id
     * @param int|string $targetTimestamp Target time
     * @return PromiseInterface Rejects with CoordinatorException when both tiers fail
     */
    public function batchAsync(string $system, array $coordinateArray, $targetTimestamp): PromiseInterface
    {
        $label = ucfirst($system);
        $viaBackup = function () use ($system, $coordinateArray, $targetTimestamp, $label): array {
            return $this->viaBackup(
                fn(CoordinatorInterface $c) => match ($system) {
                    'stonyhurst'      => $c->stonyhurstToHelioprojectiveBatch($coordinateArray, $targetTimestamp),
                    'carrington'      => $c->carringtonToHelioprojectiveBatch($coordinateArray, $targetTimestamp),
                    'helioprojective' => $c->helioprojectiveToHelioprojectiveBatch($coordinateArray, $targetTimestamp),
                },
                $label
            );
        };

        if ($this->primaryFailed) {
            try {
                return Create::promiseFor($viaBackup());
            } catch (CoordinatorException $e) {
                return Create::rejectionFor($e);
            }
        }

        return $this->primary->batchAsync($system, $coordinateArray, $targetTimestamp)
            ->otherwise(function (\Throwable $e) use ($viaBackup, $label) {
                if (!$e instanceof CoordinatorException) {
                    throw $e;
                }
                $this->primaryFailure($e, $label);
                return $viaBackup();
            });
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
                $this->primaryFailure($e, $system);
            }
        }

        return $this->viaBackup($call, $system);
    }

    /**
     * Mark the primary down for the rest of the request. Calls already in
     * flight can fail after the first; only the first one reports to Sentry.
     *
     * @param CoordinatorException $e Why the primary failed
     * @param string $system Coordinate system name for logging
     */
    private function primaryFailure(CoordinatorException $e, string $system): void
    {
        $reason = $e instanceof CoordinatorConnectionException ? 'unreachable' : 'error_response';
        if ($this->logger) {
            $this->logger->warning("FailoverCoordinator | {$system} | Primary {$reason}: " . $e->getMessage() . " | Falling back to backup");
        }
        if ($this->primaryFailed) {
            return;
        }
        $this->primaryFailed = true;
        $this->sentry->setContext('Coordinator', [
            'system' => $system,
            'tier' => 'primary',
            'reason' => $reason,
        ]);
        $this->sentry->capture($e);
    }

    /**
     * @param callable $call Receives a coordinator, returns its result
     * @param string $system Coordinate system name for logging
     * @return array
     * @throws CoordinatorException When the backup fails too
     */
    private function viaBackup(callable $call, string $system): array
    {
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
