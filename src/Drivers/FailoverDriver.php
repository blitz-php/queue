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

/**
 * Pilote de bascule : tente successivement plusieurs connexions en cas d'échec.
 */
class FailoverDriver extends Queue implements QueueContract, ConnectorInterface
{
    /**
     * Connexions ayant échoué lors de la dernière opération.
     *
     * @var list<string>
     */
    protected array $failingQueues = [];

    /**
     * Crée une instance de file en bascule (failover).
     */
    public function __construct(public Manager $manager, public QueueEventManager $events, public array $connections)
    {
    }

    /**
     * Établit une connexion de file d'attente.
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
     * Retourne le nombre total de jobs dans la file.
     */
    public function size(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->size($queue);
    }

    /**
     * Retourne le nombre de jobs en attente.
     */
    public function pendingSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->pendingSize($queue);
    }

    /**
     * Retourne le nombre de jobs retardés.
     */
    public function delayedSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->delayedSize($queue);
    }

    /**
     * Retourne le nombre de jobs réservés.
     */
    public function reservedSize(?string $queue = null): int
    {
        return $this->manager->connection($this->connections[0])->reservedSize($queue);
    }

    /**
     * Retourne les jobs en attente de la file donnée.
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        return $this->manager->connection($this->connections[0])->pendingJobs($queue);
    }

    /**
     * Retourne les jobs retardés de la file donnée.
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return $this->manager->connection($this->connections[0])->delayedJobs($queue);
    }

    /**
     * Retourne les jobs réservés de la file donnée.
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return $this->manager->connection($this->connections[0])->reservedJobs($queue);
    }

    /**
     * Retourne l'horodatage de création du plus ancien job en attente (hors retardés).
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return $this->manager
            ->connection($this->connections[0])
            ->creationTimeOfOldestPendingJob($queue);
    }

    /**
     * Envoie un nouveau job dans la file.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args(), $job);
    }

    /**
     * Envoie un payload brut dans la file.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args());
    }

    /**
     * Envoie un job dans la file après n secondes.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->attemptOnAllConnections(__FUNCTION__, func_get_args(), $job);
    }

    /**
     * Prélève le prochain job de la file.
     */
    public function pop(?string $queue = null): ?Job
    {
        return $this->manager->connection($this->connections[0])->pop($queue);
    }

    /**
     * Tente la méthode donnée sur toutes les connexions, dans l'ordre.
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

                    if ($job !== null && ! in_array($connection, $this->failingQueues, true)) {
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
