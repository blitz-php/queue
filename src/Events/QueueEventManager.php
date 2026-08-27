<?php

namespace BlitzPHP\Queue\Events;

use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Queue\Enums\WorkerStopReason;
use BlitzPHP\Queue\DTO\WorkerOptions;
use Closure;
use DateInterval;
use DateTimeInterface;
use Throwable;

class QueueEventManager
{
	// Event names for queue operations
    public const JOB_POPPING                    = 'queue.job.popping';
    public const JOB_POPPED                     = 'queue.job.popped';
    public const JOB_PUSHED                     = 'queue.job.pushed';
    public const JOB_PUSH_FAILED                = 'queue.job.push.failed';
    public const JOB_PROCESSING                 = 'queue.job.processing';
    public const JOB_PROCESSED                  = 'queue.job.processed';
    public const JOB_PROCESSING_COMPLETED       = 'queue.job.processing.completed';
    public const JOB_EXCEPTION_OCCURED          = 'queue.job.exception-occured';
    public const JOB_ATTEMPTED                  = 'queue.job.attempted';
    public const JOB_FAILED                     = 'queue.job.failed';
    public const JOB_LOOPING                    = 'queue.job.looping';
    public const JOB_RELEASED_AFTER_EXCEPTION   = 'queue.job.release-after-exception';
    public const JOB_TIMEOUT                    = 'queue.job.timeout';
    public const JOB_QUEUED                     = 'queue.job.queued';
    public const JOB_QUEUEING                   = 'queue.job.queuing';
    public const QUEUE_CLEARED                  = 'queue.cleared';
    public const QUEUE_PAUSED                   = 'queue.paused';
    public const QUEUE_RESUMED                  = 'queue.resumed';
    public const QUEUE_FAILED_OVER              = 'queue.failed-over';
    public const WORKER_STARTING                = 'queue.worker.starting';
    public const WORKER_STOPPING                = 'queue.worker.stopping';
    public const HANDLER_CONNECTION_FAILED      = 'queue.handler.connection.failed';
    public const HANDLER_CONNECTION_ESTABLISHED = 'queue.handler.connection.established';

	public function __construct(protected EventManagerInterface $events)
	{
	}

	/**
     * Emit job attempted event
     */
    public function jobAttempted(string $connection, Job $job, ?Throwable $e = null): void
	{
        $this->events->emit(new QueueEvent(
            type      : self::JOB_ATTEMPTED,
            connection: $connection,
            queue     : $job->getQueue(),
            metadata  : compact('job', 'e'),
        ));
    }

	/**
     * Emit job failed event
     */
    public function jobFailed(string $connection, Job $job, ?Throwable $e): void
	{
        $this->events->emit(new QueueEvent(
            type      : self::JOB_FAILED,
            connection: $connection,
            queue     : $job->getQueue(),
            metadata  : compact('job', 'e'),
        ));
    }

	/**
     * Emit job exception-occurent event
     */
    public function jobExceptionOccured(string $connection, Job $job, Throwable $e): void
	{
        $this->events->emit(new QueueEvent(
            type      : self::JOB_EXCEPTION_OCCURED,
            connection: $connection,
            queue     : $job->getQueue(),
            metadata  : compact('job', 'e'),
        ));
    }

	/**
     * Emit job popping event
     */
    public function jobPopping(string $connection, ?string $queue = null): void
	{
        $this->events->emit(new QueueEvent(
            type      : self::JOB_POPPING,
            connection: $connection,
            queue     : $queue,
       ));
    }

	/**
     * Emit job popped event
     */
    public function jobPopped(string $connection, ?Job $job = null): void
	{
        $this->events->emit(new QueueEvent(
			type      : self::JOB_POPPED,
			connection: $connection,
			queue     : $job?->getQueue(),
			metadata  : compact('job')
       ));
    }

	/**
     * Emit job processing event
     */
    public function jobProcessing(string $connection, Job $job): void
	{
        $this->events->emit(new QueueEvent(
            type      : self::JOB_PROCESSING,
            connection: $connection,
            queue     : $job->getQueue(),
            metadata  : compact('job'),
        ));
    }

	/**
     * Emit job processed event
     */
    public function jobProcessed(string $connection, Job $job): void
	{
        $this->events->emit(new QueueEvent(
            type      : self::JOB_PROCESSED,
            connection: $connection,
            queue     : $job->getQueue(),
            metadata  : compact('job'),
        ));
    }

	/**
     * Emit job processed event
     */
    public function jobQueued(string $connection, ?string $queue, string|int|null $jobId, string|object $job, string $payload, DateTimeInterface|DateInterval|int|null $delay): void
	{
        $this->events->emit(new QueueEvent(
            type      : self::JOB_QUEUED,
            connection: $connection,
            queue     : $queue,
            metadata  : compact('jobId', 'job', 'payload', 'delay'),
        ));
    }

	/**
     * Emit job processed event
     */
    public function jobQueueing(string $connection, ?string $queue, string|object $job, string $payload, DateTimeInterface|DateInterval|int|null $delay): void
	{
        $this->events->emit(new QueueEvent(
            type      : self::JOB_QUEUEING,
            connection: $connection,
            queue     : $queue,
            metadata  : compact('job', 'payload', 'delay'),
        ));
    }

	/**
     * Emit job released-after-exception started event
     */
    public function jobReleasedAfterException(string $connection, Job $job, int $backoff): void
	{
        $this->events->emit(new QueueEvent(
            type      : self::JOB_RELEASED_AFTER_EXCEPTION,
            connection: $connection,
            queue     : $job->getQueue(),
            metadata  : compact('job', 'backoff'),
        ));
    }

	/**
     * Emit job timeout event
     */
    public function jobTimeout(string $connection, string $queue, Job $job, array $metadata = []): void
	{
        $this->events->emit(new QueueEvent(
            type      : self::JOB_TIMEOUT,
            connection: $connection,
            queue     : $queue,
            metadata  : array_merge([
                'job_class' => $job->payload['job'],
                'job'       => $job,
            ], $metadata),
        ));
    }

	/**
     * Emit queue cleared event
     */
    public function queueCleared(string $connection, ?string $queue = null): void
	{
        $this->events->emit(new QueueEvent(
            type      : self::QUEUE_CLEARED,
            connection: $connection,
            queue     : $queue,
        ));
    }

	/**
     * Emit queue paused event
     */
    public function queuePaused(string $connection, string $queue, DateTimeInterface|DateInterval|int|null  $ttl = null): void
	{
        $this->events->emit(new QueueEvent(
			type      : self::QUEUE_PAUSED,
			connection: $connection,
			queue     : $queue,
			metadata  : compact('ttl'),
        ));
    }

	/**
     * Emit queue resumed event
     */
    public function queueResumed(string $connection, string $queue): void
	{
       	$this->events->emit(new QueueEvent(
			type      : self::QUEUE_RESUMED,
			connection: $connection,
			queue     : $queue,
        ));
    }

	/**
     * Emit queue resumed event
     */
    public function queueFailedOver(string $connection, string $job, Throwable $e): void
	{
       	$this->events->emit(new QueueEvent(
            type      : self::QUEUE_FAILED_OVER,
            connection: $connection,
            metadata  : compact('job', 'e'),
        ));
    }

	/**
     * Emit worker started event
     */
    public function workerStarting(string $connection, string $queue, WorkerOptions $options): void
	{
		$this->events->emit(new QueueEvent(
			type      : self::WORKER_STARTING,
			connection: $connection,
			queue     : $queue,
			metadata  : compact('options')
		));
    }

    /**
     * Emit worker stopped event
     */
	 public function workerStopping(string $connection, int $status, ?WorkerOptions $options = null, ?WorkerStopReason $reason = null): void
	{
		$this->events->emit(new QueueEvent(
			type      : self::WORKER_STOPPING,
			connection: $connection,
			metadata  : compact('status', 'options', 'reason')
		));
    }

	/**
     * Emit handler connection established event
     */
    public function handlerConnectionEstablished(string $connection, array $config = []): void
	{
		$this->events->emit(new QueueEvent(
			type      : self::HANDLER_CONNECTION_ESTABLISHED,
			connection: $connection,
			metadata  : compact('config')
		));
    }

    /**
     * Emit handler connection failed event
     */
    public function handlerConnectionFailed(string $connection, Throwable $exception, array $config = []): void
	{
		$this->events->emit(new QueueEvent(
			type      : self::HANDLER_CONNECTION_FAILED,
			connection: $connection,
			metadata  : compact('config', 'exception')
		));
    }
}
