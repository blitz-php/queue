<?php

namespace BlitzPHP\Queue\Jobs;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Queue\Job as JobContract;

class SyncJob extends Job implements JobContract
{
    /**
     * The class name of the job.
     *
     * @var string
     */
    protected $job;

    /**
     * Create a new job instance.
     *
     * @param  string  $payload The queue message data.
     */
    public function __construct(ContainerInterface $container, protected string $payload, string $connectionName, string $queue)
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
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        return 1;
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): string
    {
        return '';
    }

    /**
     * Get the raw body string for the job.
     */
    public function getRawBody(): string
    {
        return $this->payload;
    }

    /**
     * Get the name of the queue the job belongs to.
     */
    public function getQueue(): string
    {
        return 'sync';
    }
}
