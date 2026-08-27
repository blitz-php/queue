<?php

namespace BlitzPHP\Queue\Drivers;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Contracts\Queue\Queue as QueueContract;
use BlitzPHP\Queue\Queue;
use BlitzPHP\Utilities\Iterable\Collection;
use DateInterval;
use DateTimeInterface;

class NullDriver extends Queue implements QueueContract, ConnectorInterface
{
    /**
     * Establish a queue connection.
     */
    public static function connect(ContainerInterface $container, array $config): QueueContract
    {
        return new self;
    }

    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Get the pending jobs for the given queue.
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get the delayed jobs for the given queue.
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get the reserved jobs for the given queue.
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return null;
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(string|object $job, mixed $data = '', ?string $queue = null): mixed
    {
        return null;
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return null;
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateTimeInterface|DateInterval|int $delay, string|object $job, mixed $data = '', ?string $queue = null): mixed
    {
        return null;
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?Job
    {
        return null;
    }
}
