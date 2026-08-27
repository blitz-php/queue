<?php
namespace BlitzPHP\Queue\Traits;

use BlitzPHP\Queue\Config\Services;
use DateTimeInterface;
use DateInterval;

trait Dispatchable
{
    /**
     * Dispatch le job sur la queue
     */
    public static function dispatch(mixed ...$parameters): mixed
    {
        $job = new static(...$parameters);

        return Services::queue()->push($job, queue: $job->queue ?? null);
    }

    /**
     * Dispatch le job sur une queue spécifique
     */
    public static function dispatchOn(string $queue, mixed ...$parameters): mixed
    {
        $job = new static(...$parameters);

        return Services::queue()->pushOn($queue, $job);
    }

    /**
     * Dispatch le job avec délai
     */
    public static function dispatchLater(DateTimeInterface|DateInterval|int $delay, mixed ...$parameters): mixed
    {
        $job = new static(...$parameters);

        return Services::queue()->later($delay, $job, queue: $job->queue ?? null);
    }

    /**
     * Dispatch le job sur une queue spécifique avec délai
     */
    public static function dispatchLaterOn(string $queue, DateTimeInterface|DateInterval|int $delay, ...$parameters): mixed
    {
        $job = new static(...$parameters);

        return Services::queue()->laterOn($queue, $delay, $job);
    }

    /**
     * Dispatch le job immédiatement (synchrone)
     */
    public static function dispatchSync(mixed ...$parameters): void
    {
        $job = new static(...$parameters);

        $job->handle();
    }
}
