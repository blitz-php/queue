<?php

namespace BlitzPHP\Queue\Failed;

use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Utilities\Date;
use DateTimeInterface;
use Throwable;

class DatabaseUuidFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    /**
     * Create a new database failed job provider.
     *
     * @param  ConnectionResolverInterface  $resolver The connection resolver implementation.
     * @param  string  $database The database connection name.
     * @param  string  $table The database table.
     */
    public function __construct(protected ConnectionResolverInterface $resolver, protected string $database, protected string $table)
    {
    }

    /**
     * Log a failed job into storage.
     */
    public function log(string $connection, string $queue, string $payload, Throwable $exception): ?string
    {
        $this->getTable()->insert([
            'uuid'       => $uuid = json_decode($payload, true)['uuid'],
            'connection' => $connection,
            'queue'      => $queue,
            'payload'    => $payload,
            'exception'  => (string) mb_convert_encoding($exception, 'UTF-8'),
            'failed_at'  => Date::now()->format('Y-m-d H:i:s'),
        ]);

        return $uuid;
    }

    /**
     * Get the IDs of all of the failed jobs.
     */
    public function ids(?string $queue = null): array
    {
        return $this->getTable()
            ->when(! is_null($queue), fn ($query) => $query->where('queue', $queue))
            ->orderBy('id', 'desc')
            ->values('uuid');
    }

    /**
     * Get a list of all of the failed jobs.
     */
    public function all(): array
    {
        $records = $this->getTable()->orderBy('id', 'desc')->all();

        return collect($records)->map(function ($record) {
            $record->id = $record->uuid;
            unset($record->uuid);

            return $record;
        })->all();
    }

    /**
     * Get a single failed job.
     */
    public function find(string|int $id): ?object
    {
        if ($record = $this->getTable()->where('uuid', $id)->first()) {
            $record->id = $record->uuid;
            unset($record->uuid);
        }

        return $record;
    }

    /**
     * Delete a single failed job from storage.
     */
    public function forget(string|int $id): bool
    {
        return $this->getTable()->where('uuid', $id)->delete() > 0;
    }

    /**
     * Flush all of the failed jobs from storage.
     */
    public function flush(?int $hours = null): void
    {
        $this->getTable()->when($hours, function ($query, $hours) {
            $query->where('failed_at <=', Date::now()->subHours($hours)->format('Y-m-d H:i:s'));
        })->delete();
    }

    /**
     * Prune all of the entries older than the given date.
     */
    public function prune(DateTimeInterface $before): int
    {
        $query = $this->getTable()->where('failed_at <', $before->format('Y-m-d H:i:s'));

        $totalDeleted = 0;

        do {
            $deleted = $query->limit(1000)->delete();

            $totalDeleted += $deleted;
        } while ($deleted !== 0);

        return $totalDeleted;
    }

    /**
     * Count the failed jobs.
     */
    public function count(?string $connection = null, ?string $queue = null): int
    {
        return $this->getTable()
            ->when($connection, fn ($builder) => $builder->where('connection', $connection))
            ->when($queue, fn ($builder) => $builder->where('queue', $queue))
            ->count();
    }

    /**
     * Get a new query builder instance for the table.
     *
     * @return BaseBuilder
     */
    public function getTable()
    {
        return $this->resolver->connection($this->database)->table($this->table);
    }
}
