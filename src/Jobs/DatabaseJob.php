<?php

namespace BlitzPHP\Queue\Jobs;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Queue\Job as JobContract;
use BlitzPHP\Queue\Drivers\DatabaseDriver;

class DatabaseJob extends Job implements JobContract
{
    /**
     * Create a new job instance.
     *
     * @param  DatabaseDriver  $database The database driver instance.
     * @param  DatabaseJobRecord  $job The database job payload.
     */
    public function __construct(ContainerInterface $container, protected DatabaseDriver $database, protected DatabaseJobRecord $job, string $connectionName, string $queue)
    {
        $this->queue = $queue;
        $this->container = $container;
        $this->connectionName = $connectionName;
    }

    /**
     * Release the job back into the queue after (n) seconds.
     */
    public function release(int $delay = 0): void
    {
        parent::release($delay);

        $this->database->deleteAndRelease($this->queue, $this, $delay);
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        parent::delete();

        $this->database->deleteReserved($this->queue, $this->job->id);
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        return (int) $this->job->attempts;
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        return (string) $this->job->id;
    }

    /**
     * Get the raw body string for the job.
     */
    public function getRawBody(): string
    {
        return $this->job->payload;
    }

    /**
     * Get the database job record.
     */
    public function getJobRecord(): DatabaseJobRecord
    {
        return $this->job;
    }
}
