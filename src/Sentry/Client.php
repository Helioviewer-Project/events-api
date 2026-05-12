<?php declare(strict_types=1);

namespace Helioviewer\EventsApi\Sentry;

/**
 * Sentry client backed by the sentry/sentry SDK.
 *
 * @package Helioviewer\EventsApi\Sentry
 * @author  Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 */
class Client implements ClientInterface
{
    /**
     * @param array{dsn: string, sample_rate: float|string, traces_sample_rate: float|string, environment: string} $config
     */
    public function __construct(array $config)
    {
        \Sentry\init([
            'dsn' => $config['dsn'],
            'sample_rate' => (float) $config['sample_rate'],
            'traces_sample_rate' => (float) $config['traces_sample_rate'],
            'environment' => $config['environment'],
        ]);
    }

    public function capture(\Throwable $exception): void
    {
        \Sentry\captureException($exception);
    }

    public function message(string $message): void
    {
        \Sentry\captureMessage($message);
    }

    public function setContext(string $name, array $params): void
    {
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($name, $params): void {
            $scope->setContext($name, $params);
        });
    }

    public function setTag(string $tag, string $value): void
    {
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($tag, $value): void {
            $scope->setTag($tag, $value);
        });
    }

    public function withTransaction(string $name, string $op, callable $fn): mixed
    {
        $context = \Sentry\Tracing\TransactionContext::make()
            ->setName($name)
            ->setOp($op);

        $transaction = \Sentry\startTransaction($context);
        \Sentry\SentrySdk::getCurrentHub()->setSpan($transaction);

        try {
            $result = $fn();
            $transaction->setStatus(\Sentry\Tracing\SpanStatus::ok());
            return $result;
        } catch (\Throwable $e) {
            $transaction->setStatus(\Sentry\Tracing\SpanStatus::internalError());
            throw $e;
        } finally {
            $transaction->finish();
        }
    }
}
