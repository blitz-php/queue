<?php

namespace BlitzPHP\Queue\Events;

use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Event\Event;
use BlitzPHP\Utilities\Date;
use BlitzPHP\Utilities\String\Text;
use Throwable;

/**
 * @property mixed $job
 * @property ?int $jobId
 * @property ?int $attempts
 * @property ?Throwable $exception
 */
class QueueEvent extends Event
{
    private readonly Date $timestamp;

    public function __construct(
        public readonly string $type,
        public readonly string $connection,
        public readonly ?string $queue = null,
        private readonly array $metadata = [],
        ?Date $timestamp = null,
    ) {
		parent::__construct($this->type);

        $this->timestamp = $timestamp ?? Date::now();
    }

    /**
     * Get timestamp
     */
    public function timestamp(): Date
    {
        return $this->timestamp;
    }

    /**
     * Get all metadata
     */
    public function allMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get metadata value by key
     */
    public function metadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Check if this is a job-related event
     */
    public function isJobEvent(): bool
    {
        return str_starts_with($this->type, 'queue.job.');
    }

    /**
     * Check if this is a worker-related event
     */
    public function isWorkerEvent(): bool
    {
        return str_starts_with($this->type, 'queue.worker.');
    }

    /**
     * Check if this is an operation event (like queue.cleared)
     */
    public function isOperationEvent(): bool
    {
        return str_contains($this->type, 'queue.')
               && ! $this->isJobEvent()
               && ! $this->isWorkerEvent()
               && ! $this->isConnectionEvent();
    }

    /**
     * Check if this is a connection event
     */
    public function isConnectionEvent(): bool
    {
        return str_starts_with($this->type, 'queue.connection.');
    }

    /**
     * Get job ID (for job events)
     */
    public function getJobId(): ?int
    {
        $job = $this->job;

        return $job instanceof Job ? $job->getJobId() : $this->metadata('job_id');
    }

    /**
     * Get number of attempts (for job events)
     */
    public function getAttempts(): ?int
    {
        $job = $this->job;

        return $job instanceof Job ? $job->attempts() : $this->metadata('attempts');
    }

    /**
     * Get job status (for job events)
     */
    public function getStatus(): ?int
    {
        $job = $this->job;

        return $job instanceof Job ? $job->status : $this->metadata('status');
    }

    /**
     * Get job class name (for job events)
     */
    public function getJobClass(): ?string
    {
        return $this->metadata('job_class');
    }

    /**
     * Get processing time in seconds (for job events)
     */
    public function getProcessingTime(): float
    {
        return (float) $this->metadata('processing_time', 0.0);
    }

    /**
     * Get processing time in milliseconds (for job events)
     */
    public function getProcessingTimeMs(): int
    {
        return (int) ($this->getProcessingTime() * 1000);
    }

    /**
     * Get exception (for failed events)
     */
    public function getException(): ?Throwable
    {
        return $this->metadata('exception') ?? $this->metadata('e');
    }

    /**
     * Get exception message (for failed events)
     */
    public function getExceptionMessage(): ?string
    {
        return $this->getException()?->getMessage();
    }

    /**
     * Check if event has failed
     */
    public function hasFailed(): bool
    {
		$job = $this->job;

		return $job instanceof Job ? $job->hasFailed() : $this->getException() !== null;
    }

    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'type'      => $this->type,
            'connection'   => $this->connection,
            'queue'     => $this->queue,
            'metadata'  => $this->metadata,
            'timestamp' => $this->timestamp->toDateTimeString(),
        ];
    }

    public function __get(string $name): mixed
    {
        if (method_exists($this, $method = 'get' . Text::camel($name))) {
            return $this->{$method}();
        }

        return $this->metadata($name);
    }
}
