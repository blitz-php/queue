<?php

namespace BlitzPHP\Queue\Drivers;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Database\ConnectionInterface;
use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Contracts\Queue\Queue as QueueContract;
use BlitzPHP\Exceptions\CriticalError;
use BlitzPHP\Queue\Events\QueueEventManager;
use BlitzPHP\Queue\Models\JobModel;
use BlitzPHP\Queue\Queue;
use BlitzPHP\Queue\Jobs\DatabaseJob;
use BlitzPHP\Queue\Jobs\DatabaseJobRecord;
use BlitzPHP\Queue\Jobs\InspectedJob;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\String\Stringable;
use BlitzPHP\Utilities\String\Text;
use DateTimeInterface;
use DateInterval;
use Throwable;

class DatabaseDriver extends Queue implements QueueContract, ConnectorInterface
{
    /**
     * The cached lock type for popping jobs.
     *
     * @var string|bool|null
     */
    protected $lockForPopping = null;

    /**
     * Create a new database queue instance.
     * 
     * @param string $default The name of the default queue.
     */
    public function __construct(protected JobModel $model, protected string $default = 'default', bool $dispatchAfterCommit = false)
	{
        $this->dispatchAfterCommit = $dispatchAfterCommit;
    }

	/**
     * Establish a queue connection.
     *
     * @param  array  $config
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
                $config['after_commit'] ?? false
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
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return $this->model->size($this->getQueue($queue));
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(?string $queue = null): int
    {
        return $this->model->pendingSize($this->getQueue($queue));
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(?string $queue = null): int
    {
        return $this->model->delayedSize($this->getQueue($queue));
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(?string $queue = null): int
    {
        return $this->model->reservedSize($this->getQueue($queue));
    }

    /**
     * Get the pending jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        return collect($this->model->pendingJobs($this->getQueue($queue)))
            ->map(fn ($record) => InspectedJob::fromPayload($record->payload, $record->attempts));
    }

    /**
     * Get the delayed jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return collect($this->model->delayedJobs($this->getQueue($queue)))
            ->map(fn ($record) => InspectedJob::fromPayload($record->payload, $record->attempts));
    }

    /**
     * Get the reserved jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return  collect($this->model->reservedJobs($this->getQueue($queue)))
            ->map(fn ($record) => InspectedJob::fromPayload($record->payload, $record->attempts));
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return $this->model->creationTimeOfOldestPendingJob($this->getQueue($queue));
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(string|object $job, mixed $data = '', ?string $queue = null): mixed
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
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->pushToDatabase($queue, $payload);
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateTimeInterface|DateInterval|int $delay, string|object $job, mixed $data = '', ?string $queue = null): mixed
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
     * Push an array of jobs onto the queue.
	 */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        $queue = $this->getQueue($queue);

        $now = $this->availableAt();

        $this->model->insert((new Collection((array) $jobs))->map(
            function ($job) use ($queue, $data, $now) {
                return $this->buildDatabaseRecord(
                    $queue,
                    $this->createPayload($job, $this->getQueue($queue), $data),
                    isset($job->delay) ? $this->availableAt($job->delay) : $now,
                );
            }
        )->all());

		return null;
    }

    /**
     * Release a reserved job back onto the queue after (n) seconds.
     */
    public function release(string $queue, DatabaseJobRecord $job, int $delay): mixed
    {
        return $this->pushToDatabase($queue, $job->payload, $delay, $job->attempts);
    }

    /**
     * Push a raw payload to the database with a given delay of (n) seconds.
     */
    protected function pushToDatabase(?string $queue, string $payload, DateTimeInterface|DateInterval|int $delay = 0, int $attempts = 0): mixed
    {
        return $this->model->pushToDatabase($this->buildDatabaseRecord(
            $this->getQueue($queue),
            $payload,
            $this->availableAt($delay),
            $attempts
        ));
    }

    /**
     * Create an array to insert for the given job.
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
     * Pop the next job off of the queue.
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
            // Potentially invalid job that we need to fail (#58978)...
            if ($jobRecord) {
                try {
                    (new DatabaseJob(
                        $this->container, $this, $jobRecord, $this->connectionName, $queue
                    ))->fail($e);
                } catch (Throwable) {
                    // Ignore and throw the original exception...
                }
            }

            throw $e;
        }
    }

    /**
     * Get the next available job for the queue.
     */
    protected function getNextAvailableJob(?string $queue): ?DatabaseJobRecord
    {
        $job = $this->model->getNextAvailableJob($this->getQueue($queue));

        return $job ? new DatabaseJobRecord((object) $job) : null;
    }

    /**
     * Get the lock required for popping the next job.
     *
     * @return string|bool
     */
    protected function getLockForPopping()
    {
        if ($this->lockForPopping !== null) {
            return $this->lockForPopping;
        }

        $databaseEngine= $this->model->db()->getPlatform();
        $databaseVersion= $this->model->db()->getVersion();

        if ((new Stringable($databaseVersion))->contains('MariaDB')) {
            $databaseEngine = 'mariadb';
            $databaseVersion = Text::before(Text::after($databaseVersion, '5.5.5-'), '-');
        } elseif ((new Stringable($databaseVersion))->contains(['vitess', 'PlanetScale'])) {
            $databaseEngine = 'vitess';
            $databaseVersion = Text::before($databaseVersion, '-');
        }

        if (($databaseEngine === 'mysql' && version_compare($databaseVersion, '8.0.1', '>=')) ||
            ($databaseEngine === 'mariadb' && version_compare($databaseVersion, '10.6.0', '>=')) ||
            ($databaseEngine === 'pgsql' && version_compare($databaseVersion, '9.5', '>=')) ||
            ($databaseEngine === 'vitess' && version_compare($databaseVersion, '19.0', '>='))
        ) {
            return $this->lockForPopping = 'FOR UPDATE SKIP LOCKED';
        }

        if ($databaseEngine === 'sqlsrv') {
            return $this->lockForPopping = 'with(rowlock,updlock,readpast)';
        }

        return $this->lockForPopping = true;
    }

    /**
     * Marshal the reserved job into a DatabaseJob instance.
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
     * Mark the given job ID as reserved.
     */
    protected function markJobAsReserved(DatabaseJobRecord $job): DatabaseJobRecord
    {
        $this->model->where('id', $job->id)->update([
            'reserved_at' => $job->touch(),
            'attempts' => $job->increment(),
        ]);

        return $job;
    }

    /**
     * Delete a reserved job from the queue.
     *
     * @throws Throwable
     */
    public function deleteReserved(string $queue, string $id): void
    {
        $this->model->deleteReserved($queue, $id);
    }

    /**
     * Delete a reserved job from the reserved queue and release it.
     */
    public function deleteAndRelease(string $queue, DatabaseJob $job, int $delay): void
    {
        $this->model->transaction(function () use ($queue, $job, $delay) {
			$where = ['id' => $job->getJobId()];

            if ($this->model/*->lockForUpdate()*/->where($where)->first()) {
                $this->model->where($where)->delete();
            }

            $this->release($queue, $job->getJobRecord(), $delay);
        });
    }

    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(string $queue): bool
    {
        return $this->model->clear($this->getQueue($queue));
    }

    /**
     * Get the queue or return the default.
     */
    public function getQueue(?string $queue): string
    {
        return $queue ?: $this->default;
    }

    /**
     * Get the underlying database instance.
     */
    public function getDatabase(): ConnectionInterface
    {
        return $this->model->db();
    }
}
