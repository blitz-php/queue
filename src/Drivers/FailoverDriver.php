<?php

namespace BlitzPHP\Queue\Drivers;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Contracts\Queue\Queue as QueueContract;
use BlitzPHP\Queue\Events\QueueEventManager;
use BlitzPHP\Queue\Manager;
use BlitzPHP\Queue\Queue;
use BlitzPHP\Utilities\Iterable\Collection;
use DateInterval;
use DateTimeInterface;
use RuntimeException;
use Throwable;

class FailoverDriver extends Queue implements QueueContract, ConnectorInterface
{
    /**
     * The queues which failed on the last action.
     *
     * @var list<string>
     */
    protected array $failingQueues = [];

    /**
     * Create a new failover queue instance.
     */
    public function __construct(public Manager $manager, public QueueEventManager $events, public array $connections)
    {
    }

	/**
     * Establish a queue connection.
     */
    public static function connect(ContainerInterface $container, array $config): QueueContract
    {
        return new self(
            $container->make(Manager::class),
            $container->make(QueueEventManager::class),
            $config['connections'],
        );
    }

    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->size($queue);
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->pendingSize($queue);
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->delayedSize($queue);
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->reservedSize($queue);
    }

    /**
     * Get the pending jobs for the given queue.
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        return $this->manager->connection($this->connections[0])->pendingJobs($queue);
    }

    /**
     * Get the delayed jobs for the given queue.
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return $this->manager->connection($this->connections[0])->delayedJobs($queue);
    }

    /**
     * Get the reserved jobs for the given queue.
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return $this->manager->connection($this->connections[0])->reservedJobs($queue);
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return $this->manager
            ->connection($this->connections[0])
            ->creationTimeOfOldestPendingJob($queue);
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args(), $job);
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args());
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateTimeInterface|DateInterval|int $delay, string|object $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args(), $job);
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?Job
    {
        return $this->manager->connection($this->connections[0])->pop($queue);
    }

    /**
     * Attempt the given method on all connections.
     *
     *
     * @throws Throwable
     */
    protected function attemptOnAllConnections(string $method, array $arguments, ?string $job = null): mixed
    {
        [$lastException, $failedQueues] = [null, []];

        try {
            foreach ($this->connections as $connection) {
                try {
                    return $this->manager->connection($connection)->{$method}(...$arguments);
                } catch (Throwable $e) {
                    $lastException = $e;

                    $failedQueues[] = $connection;

                    if ($job !== null && ! in_array($connection, $this->failingQueues)) {
                        $this->events->queueFailedOver($connection, $job, $e);
                    }
                }
            }
        } finally {
            $this->failingQueues = $failedQueues;
        }

        throw $lastException ?? new RuntimeException('All failover queue connections failed.');
    }
}
