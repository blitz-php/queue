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
use BlitzPHP\Contracts\Database\ConnectionInterface;
use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Contracts\Queue\Queue as QueueContract;
use BlitzPHP\Exceptions\CriticalError;
use BlitzPHP\Queue\Events\QueueEventManager;
use BlitzPHP\Queue\Jobs\DatabaseJob;
use BlitzPHP\Queue\Jobs\DatabaseJobRecord;
use BlitzPHP\Queue\Jobs\InspectedJob;
use BlitzPHP\Queue\Models\JobModel;
use BlitzPHP\Queue\Queue;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\String\Stringable;
use BlitzPHP\Utilities\String\Text;
use DateInterval;
use DateTimeInterface;
use Throwable;

/**
 * Pilote de file d'attente persisté en base de données.
 */
class DatabaseDriver extends Queue implements QueueContract, ConnectorInterface
{
    /**
     * Type de verrou mis en cache pour le prélèvement des jobs.
     *
     * @var bool|string|null
     */
    protected $lockForPopping;

    /**
     * Crée une instance de file d'attente base de données.
     *
     * @param string $default Nom de la file par défaut.
     */
    public function __construct(protected JobModel $model, protected string $default = 'default', bool $dispatchAfterCommit = false)
    {
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

    /**
     * Établit une connexion de file d'attente.
     *
     * @param array<string, mixed> $config Configuration de la connexion.
     */
    public static function connect(ContainerInterface $container, array $config): QueueContract
    {
        try {
            $connection = service('database', $config['connection'] ?? null, $config['shared'] ?? true);

            $queue = new self(
                new JobModel(
                    $config,
                    $container->get(ConnectionResolverInterface::class),
                    $connection,
                ),
                $config['queue'],
                $config['after_commit'] ?? false,
            );

            $container->get(QueueEventManager::class)->handlerConnectionEstablished(
                connection: $queue->getConnectionName(),
                config: $config,
            );

            return $queue;
        } catch (Throwable $e) {
            $container->get(QueueEventManager::class)->handlerConnectionFailed(
                connection: 'default',
                config: $config,
                exception: $e,
            );

            throw new CriticalError('Queue: Database connection failed. ' . $e->getMessage());
        }
    }

    /**
     * Retourne le nombre total de jobs dans la file.
     */
    public function size(?string $queue = null): int
    {
        return $this->model->size($this->getQueue($queue));
    }

    /**
     * Retourne le nombre de jobs en attente.
     */
    public function pendingSize(?string $queue = null): int
    {
        return $this->model->pendingSize($this->getQueue($queue));
    }

    /**
     * Retourne le nombre de jobs retardés.
     */
    public function delayedSize(?string $queue = null): int
    {
        return $this->model->delayedSize($this->getQueue($queue));
    }

    /**
     * Retourne le nombre de jobs réservés.
     */
    public function reservedSize(?string $queue = null): int
    {
        return $this->model->reservedSize($this->getQueue($queue));
    }

    /**
     * Retourne les jobs en attente de la file donnée.
     *
     * @return Collection<int, InspectedJob>
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        return collect($this->model->pendingJobs($this->getQueue($queue)))
            ->map(fn ($record) => InspectedJob::fromPayload($record->payload, $record->attempts));
    }

    /**
     * Retourne les jobs retardés de la file donnée.
     *
     * @return Collection<int, InspectedJob>
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return collect($this->model->delayedJobs($this->getQueue($queue)))
            ->map(fn ($record) => InspectedJob::fromPayload($record->payload, $record->attempts));
    }

    /**
     * Retourne les jobs réservés de la file donnée.
     *
     * @return Collection<int, InspectedJob>
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return collect($this->model->reservedJobs($this->getQueue($queue)))
            ->map(fn ($record) => InspectedJob::fromPayload($record->payload, $record->attempts));
    }

    /**
     * Retourne l'horodatage de création du plus ancien job en attente (hors retardés).
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return $this->model->creationTimeOfOldestPendingJob($this->getQueue($queue));
    }

    /**
     * Envoie un nouveau job dans la file.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            fn ($payload, $queue) => $this->pushToDatabase($queue, $payload),
        );
    }

    /**
     * Envoie un payload brut dans la file.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->pushToDatabase($queue, $payload);
    }

    /**
     * Envoie un job dans la file après n secondes.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data, $delay),
            $queue,
            $delay,
            fn ($payload, $queue, $delay) => $this->pushToDatabase($queue, $payload, $delay),
        );
    }

    /**
     * Envoie un tableau de jobs dans la file.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        $queue = $this->getQueue($queue);

        $now = $this->availableAt();

        $this->model->insert((new Collection((array) $jobs))->map(
            fn ($job) => $this->buildDatabaseRecord(
                $queue,
                $this->createPayload($job, $this->getQueue($queue), $data),
                isset($job->delay) ? $this->availableAt($job->delay) : $now,
            ),
        )->all());

        return null;
    }

    /**
     * Relâche un job réservé dans la file après n secondes.
     */
    public function release(string $queue, DatabaseJobRecord $job, int $delay): mixed
    {
        return $this->pushToDatabase($queue, $job->payload, $delay, $job->attempts);
    }

    /**
     * Insère un payload brut en base avec un délai de n secondes.
     */
    protected function pushToDatabase(?string $queue, string $payload, DateInterval|DateTimeInterface|int $delay = 0, int $attempts = 0): mixed
    {
        return $this->model->pushToDatabase($this->buildDatabaseRecord(
            $this->getQueue($queue),
            $payload,
            $this->availableAt($delay),
            $attempts,
        ));
    }

    /**
     * Construit le tableau à insérer pour le job donné.
     */
    protected function buildDatabaseRecord(?string $queue, string $payload, int $availableAt, int $attempts = 0): array
    {
        return [
            'queue'        => $queue,
            'attempts'     => $attempts,
            'reserved_at'  => null,
            'available_at' => $availableAt,
            'created_at'   => $this->currentTime(),
            'payload'      => $payload,
        ];
    }

    /**
     * Prélève le prochain job de la file.
     *
     * @throws Throwable
     */
    public function pop(?string $queue = null): ?Job
    {
        $queue = $this->getQueue($queue);

        $jobRecord = null;

        try {
            return $this->model->transaction(function () use ($queue, &$jobRecord) {
                if ($jobRecord = $this->getNextAvailableJob($queue)) {
                    return $this->marshalJob($queue, $jobRecord);
                }
            });
        } catch (Throwable $e) {
            // Job potentiellement invalide : on tente de le marquer en échec.
            if ($jobRecord) {
                try {
                    (new DatabaseJob(
                        $this->container,
                        $this,
                        $jobRecord,
                        $this->connectionName,
                        $queue,
                    ))->fail($e);
                } catch (Throwable) {
                    // Ignore et relance l'exception d'origine.
                }
            }

            throw $e;
        }
    }

    /**
     * Retourne le prochain job disponible de la file.
     */
    protected function getNextAvailableJob(?string $queue): ?DatabaseJobRecord
    {
        $job = $this->model->getNextAvailableJob($this->getQueue($queue));

        return $job ? new DatabaseJobRecord((object) $job) : null;
    }

    /**
     * Retourne le verrou SQL nécessaire pour prélever le prochain job.
     *
     * @return bool|string
     */
    protected function getLockForPopping()
    {
        if ($this->lockForPopping !== null) {
            return $this->lockForPopping;
        }

        $databaseEngine  = $this->model->db()->getPlatform();
        $databaseVersion = $this->model->db()->getVersion();

        if ((new Stringable($databaseVersion))->contains('MariaDB')) {
            $databaseEngine  = 'mariadb';
            $databaseVersion = Text::before(Text::after($databaseVersion, '5.5.5-'), '-');
        } elseif ((new Stringable($databaseVersion))->contains(['vitess', 'PlanetScale'])) {
            $databaseEngine  = 'vitess';
            $databaseVersion = Text::before($databaseVersion, '-');
        }

        if (($databaseEngine === 'mysql' && version_compare($databaseVersion, '8.0.1', '>='))
            || ($databaseEngine === 'mariadb' && version_compare($databaseVersion, '10.6.0', '>='))
            || ($databaseEngine === 'pgsql' && version_compare($databaseVersion, '9.5', '>='))
            || ($databaseEngine === 'vitess' && version_compare($databaseVersion, '19.0', '>='))
        ) {
            return $this->lockForPopping = 'FOR UPDATE SKIP LOCKED';
        }

        if ($databaseEngine === 'sqlsrv') {
            return $this->lockForPopping = 'with(rowlock,updlock,readpast)';
        }

        return $this->lockForPopping = true;
    }

    /**
     * Transforme le job réservé en instance DatabaseJob.
     */
    protected function marshalJob(string $queue, DatabaseJobRecord $job): DatabaseJob
    {
        return new DatabaseJob(
            $this->container,
            $this,
            $this->markJobAsReserved($job),
            $this->connectionName,
            $queue,
        );
    }

    /**
     * Marque le job comme réservé.
     */
    protected function markJobAsReserved(DatabaseJobRecord $job): DatabaseJobRecord
    {
        $this->model->where('id', $job->id)->update([
            'reserved_at' => $job->touch(),
            'attempts'    => $job->increment(),
        ]);

        return $job;
    }

    /**
     * Supprime un job réservé de la file.
     *
     * @throws Throwable
     */
    public function deleteReserved(string $queue, string $id): void
    {
        $this->model->deleteReserved($queue, $id);
    }

    /**
     * Supprime le job réservé puis le relâche dans la file.
     */
    public function deleteAndRelease(string $queue, DatabaseJob $job, int $delay): void
    {
        $this->model->transaction(function () use ($queue, $job, $delay) {
            $where = ['id' => $job->getJobId()];

            if ($this->model/* ->lockForUpdate() */ ->where($where)->first()) {
                $this->model->where($where)->delete();
            }

            $this->release($queue, $job->getJobRecord(), $delay);
        });
    }

    /**
     * Supprime tous les jobs de la file.
     */
    public function clear(string $queue): bool
    {
        return $this->model->clear($this->getQueue($queue));
    }

    /**
     * Retourne le nom de file, ou la file par défaut.
     */
    public function getQueue(?string $queue): string
    {
        return $queue ?: $this->default;
    }

    /**
     * Retourne l'instance de connexion base de données.
     */
    public function getDatabase(): ConnectionInterface
    {
        return $this->model->db();
    }
}
