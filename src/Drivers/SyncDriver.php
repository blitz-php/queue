<?php

namespace BlitzPHP\Queue\Drivers;

use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Contracts\Queue\Queue as QueueContract;
use BlitzPHP\Queue\Jobs\SyncJob;
use BlitzPHP\Queue\Queue;
use BlitzPHP\Utilities\Iterable\Collection;
use DateInterval;
use DateTimeInterface;
use Psr\Container\ContainerInterface;
use Throwable;

class SyncDriver extends Queue implements QueueContract, ConnectorInterface
{
    /**
     * Create a new sync queue instance.
     */
    public function __construct(bool $dispatchAfterCommit = false)
    {
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    /**
     * Establish a queue connection.
     */
    public static function connect(ContainerInterface $container, array $config): QueueContract
    {
        return new self($config['after_commit'] ?? null);
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
     *
     * @throws Throwable
     */
    public function push(string|object $job, mixed $data = '', ?string $queue = null): mixed
    {
        $job = $job instanceof Job ? $job->getJobId() : (string) $job;

        /*
        if ($this->shouldDispatchAfterCommit($job) &&
            $this->container->bound('db.transactions')) {
            if ($job instanceof ShouldBeUnique) {
                $this->container->make('db.transactions')->addCallbackForRollback(
                    function () use ($job) {
                        (new UniqueLock($this->container->make(Cache::class)))->release($job);
                    }
                );
            }

            return $this->container->make('db.transactions')->addCallback(
                fn () => $this->executeJob($job, $data, $queue)
            );
        }
        */

        return $this->executeJob($job, $data, $queue);
    }

    /**
     * Execute a given job synchronously.
     *
     * @throws Throwable
     */
    protected function executeJob(string $job, mixed $data = '', ?string $queue = null): int
    {
        $queueJob = $this->resolveJob($this->createPayload($job, $queue, $data), $queue);

        try {
            $this->raiseBeforeJobEvent($queueJob);

            $queueJob->fire();

            $this->raiseAfterJobEvent($queueJob);
        } catch (Throwable $e) {
            $exceptionOccurred = $e;

            $this->handleException($queueJob, $e);
        } finally {
            $this->raiseJobAttemptedEvent($queueJob, $exceptionOccurred ?? null);
        }

        return 0;
    }

    /**
     * Resolve a Sync job instance.
     */
    protected function resolveJob(string $payload, string $queue): SyncJob
    {
        return new SyncJob($this->container, $payload, $this->connectionName, $queue);
    }

    /**
     * Raise the before queue job event.
     */
    protected function raiseBeforeJobEvent(Job $job): void
    {
        $this->eventManager()->jobProcessing($this->connectionName, $job);
    }

    /**
     * Raise the after queue job event.
     */
    protected function raiseAfterJobEvent(Job $job): void
    {
        $this->eventManager()->jobProcessed($this->connectionName, $job);
    }

    /**
     * Raise the job attempted event.
     */
    protected function raiseJobAttemptedEvent(Job $job, ?Throwable $exceptionOccurred = null): void
    {
        $this->eventManager()->jobAttempted($this->connectionName, $job, $exceptionOccurred);
    }

    /**
     * Raise the exception occurred queue job event.
     */
    protected function raiseExceptionOccurredJobEvent(Job $job, Throwable $e): void
    {
        $this->eventManager()->jobExceptionOccured($this->connectionName, $job, $e);
    }

    /**
     * Handle an exception that occurred while processing a job.
     *
     * @throws Throwable
     */
    protected function handleException(Job $queueJob, Throwable $e): void
    {
        $this->raiseExceptionOccurredJobEvent($queueJob, $e);

        $queueJob->fail($e);

        throw $e;
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
        return $this->push($job, $data, $queue);
    }

    /**
     * Pop the next job off of the queue.
     */
    public function pop(?string $queue = null): ?Job
    {
        return null;
    }
}
