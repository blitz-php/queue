<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

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

/**
 * Pilote synchrone : exécute le job immédiatement dans le processus courant.
 */
class SyncDriver extends Queue implements QueueContract, ConnectorInterface
{
    /**
     * Crée une instance de file synchrone.
     */
    public function __construct(bool $dispatchAfterCommit = false)
    {
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    /**
     * Établit une connexion de file d'attente.
     */
    public static function connect(ContainerInterface $container, array $config): QueueContract
    {
        return new self($config['after_commit'] ?? null);
    }

    /**
     * Retourne le nombre total de jobs dans la file.
     */
    public function size(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Retourne le nombre de jobs en attente.
     */
    public function pendingSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Retourne le nombre de jobs retardés.
     */
    public function delayedSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Retourne le nombre de jobs réservés.
     */
    public function reservedSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Retourne les jobs en attente de la file donnée.
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        return new Collection();
    }

    /**
     * Retourne les jobs retardés de la file donnée.
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return new Collection();
    }

    /**
     * Retourne les jobs réservés de la file donnée.
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return new Collection();
    }

    /**
     * Retourne l'horodatage de création du plus ancien job en attente (hors retardés).
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return null;
    }

    /**
     * Envoie un nouveau job dans la file.
     *
     * @throws Throwable
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
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
     * Exécute un job de façon synchrone.
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
     * Résout une instance de job synchrone.
     */
    protected function resolveJob(string $payload, string $queue): SyncJob
    {
        return new SyncJob($this->container, $payload, $this->connectionName, $queue);
    }

    /**
     * Émet l'événement avant traitement du job.
     */
    protected function raiseBeforeJobEvent(Job $job): void
    {
        $this->eventManager()->jobProcessing($this->connectionName, $job);
    }

    /**
     * Émet l'événement après traitement du job.
     */
    protected function raiseAfterJobEvent(Job $job): void
    {
        $this->eventManager()->jobProcessed($this->connectionName, $job);
    }

    /**
     * Émet l'événement de tentative de job.
     */
    protected function raiseJobAttemptedEvent(Job $job, ?Throwable $exceptionOccurred = null): void
    {
        $this->eventManager()->jobAttempted($this->connectionName, $job, $exceptionOccurred);
    }

    /**
     * Émet l'événement d'exception survenue sur un job.
     */
    protected function raiseExceptionOccurredJobEvent(Job $job, Throwable $e): void
    {
        $this->eventManager()->jobExceptionOccured($this->connectionName, $job, $e);
    }

    /**
     * Traite une exception survenue pendant le traitement d'un job.
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
     * Envoie un payload brut dans la file.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return null;
    }

    /**
     * Envoie un job dans la file après n secondes.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Prélève le prochain job de la file.
     */
    public function pop(?string $queue = null): ?Job
    {
        return null;
    }
}
