<?php declare(strict_types=1);

namespace Helioviewer\EventsApi\Sentry;

/**
 * Null-object Sentry client.
 * Used when Sentry is disabled; all methods are no-ops.
 *
 * @package Helioviewer\EventsApi\Sentry
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class VoidClient implements ClientInterface
{
    public function __construct(array $config)
    {
    }

    public function capture(\Throwable $exception): void
    {
    }

    public function message(string $message): void
    {
    }

    public function setContext(string $name, array $params): void
    {
    }

    public function setTag(string $tag, string $value): void
    {
    }

    public function withTransaction(string $name, string $op, callable $fn): mixed
    {
        return $fn();
    }
}
