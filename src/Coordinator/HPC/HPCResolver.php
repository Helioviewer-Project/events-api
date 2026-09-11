<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Coordinator\HPC;

use Helioviewer\EventsApi\Coordinator\CoordinatorInterface;
use Helioviewer\EventsApi\Coordinator\HPC\Strategies\HPCStrategyInterface;
use Helioviewer\EventsApi\Coordinator\HPC\Strategies\HelioprojectiveStrategy;
use Helioviewer\EventsApi\Coordinator\HPC\Strategies\StonyhurstStrategy;
use Helioviewer\EventsApi\Coordinator\HPC\Strategies\CarringtonStrategy;
use Illuminate\Database\Eloquent\Collection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fills x_hpc, y_hpc and footprint_hpc on a batch of events.
 *
 * Partitions the batch by the first strategy whose applies() claims each
 * event, then hands each bucket to that strategy in one call so coordinator
 * requests stay batched. Events are mutated in-place; the caller saves.
 *
 * @package Helioviewer\EventsApi\Coordinator\HPC
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since   1.0.0
 */
class HPCResolver
{
    /** @var HPCStrategyInterface[] */
    private array $strategies;
    private LoggerInterface $logger;

    /**
     * @param HPCStrategyInterface[] $strategies Ordered; first match wins
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(array $strategies, ?LoggerInterface $logger = null)
    {
        $this->strategies = $strategies;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Build the resolver with the three built-in strategies.
     *
     * @param CoordinatorInterface $coordinator Coordinator (wrap in FailoverCoordinator for backup support)
     * @param LoggerInterface|null $logger Optional logger
     * @return self
     */
    public static function createDefault(CoordinatorInterface $coordinator, ?LoggerInterface $logger = null): self
    {
        return new self([
            new HelioprojectiveStrategy($logger),
            new StonyhurstStrategy($coordinator, $logger),
            new CarringtonStrategy($coordinator, $logger),
        ], $logger);
    }

    /**
     * Resolve the HPC fields for every event a strategy claims.
     *
     * @param Collection $events Events to resolve
     * @return void
     */
    public function resolve(Collection $events): void
    {
        if ($events->isEmpty()) {
            return;
        }

        // Group the batch by strategy so each system costs one call, not one per event.
        $buckets = [];
        $unclaimed = 0;

        foreach ($events as $event) {
            foreach ($this->strategies as $index => $strategy) {
                if ($strategy->applies($event)) {
                    $buckets[$index][] = $event;
                    continue 2;
                }
            }
            $unclaimed++;
        }

        // An unknown coordinate_system would silently never resolve — say so.
        if ($unclaimed > 0) {
            $this->logger->warning("HPCResolver | {$unclaimed} events claimed by no strategy");
        }

        // Hand each bucket to its strategy whole, keeping coordinator requests batched.
        foreach ($buckets as $index => $bucket) {
            $this->strategies[$index]->apply(new Collection($bucket));
        }
    }
}
