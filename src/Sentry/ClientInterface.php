<?php declare(strict_types=1);

namespace Helioviewer\EventsApi\Sentry;

/**
 * Sentry client interface
 *
 * @package Helioviewer\EventsApi\Sentry
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
interface ClientInterface
{
    /**
     * Captures an exception and sends it to Sentry.
     *
     * @param \Throwable $exception The exception to capture.
     * @return void
     */
    public function capture(\Throwable $exception): void;

    /**
     * Sends a message to Sentry.
     *
     * @param string $message The message to send.
     * @return void
     */
    public function message(string $message): void;

    /**
     * Sets the context for the Sentry client.
     *
     * @param string               $name   The name of the context.
     * @param array<string, mixed> $params The parameters in the context.
     * @return void
     */
    public function setContext(string $name, array $params): void;

    /**
     * Sets a tag for the Sentry client.
     *
     * @param string $tag   The name of the tag.
     * @param string $value The value of the tag.
     * @return void
     */
    public function setTag(string $tag, string $value): void;

    /**
     * Wraps a callable in a Sentry performance transaction.
     * The transaction is finished when the callable returns or throws.
     *
     * @param string   $name The transaction name (e.g. "scheduler.every_6_minutes")
     * @param string   $op   The operation category (e.g. "cron", "cli")
     * @param callable $fn   The work to measure; its return value is returned.
     * @return mixed
     */
    public function withTransaction(string $name, string $op, callable $fn): mixed;
}
