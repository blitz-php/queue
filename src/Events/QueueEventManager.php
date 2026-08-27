<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Events;

use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Queue\DTO\WorkerOptions;
use BlitzPHP\Queue\Enums\WorkerStopReason;
use DateInterval;
use DateTimeInterface;
use Throwable;

/**
 * Émet les événements du cycle de vie des files, jobs et workers.
 */
class QueueEventManager
{
    /**
     * Noms d'événements des opérations de file.
     */
    public const JOB_POPPING = 'queue.job.popping';

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

    /**
     * @param EventManagerInterface $events Gestionnaire d'événements de l'application.
     */
    public function __construct(protected EventManagerInterface $events)
    {
    }

    /**
     * Émet l'événement de tentative de job.
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
     * Émet l'événement d'échec de job.
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
     * Émet l'événement d'exception survenue sur un job.
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
     * Émet l'événement de prélèvement imminent d'un job.
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
     * Émet l'événement de job prélevé.
     */
    public function jobPopped(string $connection, ?Job $job = null): void
    {
        $this->events->emit(new QueueEvent(
            type      : self::JOB_POPPED,
            connection: $connection,
            queue     : $job?->getQueue(),
            metadata  : compact('job'),
        ));
    }

    /**
     * Émet l'événement de traitement en cours.
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
     * Émet l'événement de job traité.
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
     * Émet l'événement « job enfilé ».
     */
    public function jobQueued(string $connection, ?string $queue, int|string|null $jobId, object|string $job, string $payload, DateInterval|DateTimeInterface|int|null $delay): void
    {
        $this->events->emit(new QueueEvent(
            type      : self::JOB_QUEUED,
            connection: $connection,
            queue     : $queue,
            metadata  : compact('jobId', 'job', 'payload', 'delay'),
        ));
    }

    /**
     * Émet l'événement « job en cours d'enfilement ».
     */
    public function jobQueueing(string $connection, ?string $queue, object|string $job, string $payload, DateInterval|DateTimeInterface|int|null $delay): void
    {
        $this->events->emit(new QueueEvent(
            type      : self::JOB_QUEUEING,
            connection: $connection,
            queue     : $queue,
            metadata  : compact('job', 'payload', 'delay'),
        ));
    }

    /**
     * Émet l'événement de relâchement après exception.
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
     * Émet l'événement de dépassement de délai.
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
     * Émet l'événement de file vidée.
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
     * Émet l'événement de file en pause.
     */
    public function queuePaused(string $connection, string $queue, DateInterval|DateTimeInterface|int|null $ttl = null): void
    {
        $this->events->emit(new QueueEvent(
            type      : self::QUEUE_PAUSED,
            connection: $connection,
            queue     : $queue,
            metadata  : compact('ttl'),
        ));
    }

    /**
     * Émet l'événement de reprise de file.
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
     * Émet l'événement de bascule (failover) vers une autre connexion.
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
     * Émet l'événement de démarrage du worker.
     */
    public function workerStarting(string $connection, string $queue, WorkerOptions $options): void
    {
        $this->events->emit(new QueueEvent(
            type      : self::WORKER_STARTING,
            connection: $connection,
            queue     : $queue,
            metadata  : compact('options'),
        ));
    }

    /**
     * Émet l'événement d'arrêt du worker.
     */
    public function workerStopping(string $connection, int $status, ?WorkerOptions $options = null, ?WorkerStopReason $reason = null): void
    {
        $this->events->emit(new QueueEvent(
            type      : self::WORKER_STOPPING,
            connection: $connection,
            metadata  : compact('status', 'options', 'reason'),
        ));
    }

    /**
     * Émet l'événement de connexion de pilote établie.
     */
    public function handlerConnectionEstablished(string $connection, array $config = []): void
    {
        $this->events->emit(new QueueEvent(
            type      : self::HANDLER_CONNECTION_ESTABLISHED,
            connection: $connection,
            metadata  : compact('config'),
        ));
    }

    /**
     * Émet l'événement d'échec de connexion de pilote.
     */
    public function handlerConnectionFailed(string $connection, Throwable $exception, array $config = []): void
    {
        $this->events->emit(new QueueEvent(
            type      : self::HANDLER_CONNECTION_FAILED,
            connection: $connection,
            metadata  : compact('config', 'exception'),
        ));
    }
}
