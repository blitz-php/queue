<?php

namespace BlitzPHP\Queue\Failed;

use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Utilities\Date;
use DateTimeInterface;
use Throwable;

class DatabaseFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
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
    public function log(string $connection, string $queue, string $payload, Throwable $exception): ?int
    {
        $failed_at = Date::now();

        $exception = (string) mb_convert_encoding($exception, 'UTF-8');

        return $this->insertGetId(compact(
            'connection', 'queue', 'payload', 'exception', 'failed_at'
        ));
    }

    /**
     * Get the IDs of all of the failed jobs.
     */
    public function ids(?string $queue = null): array
    {
        return $this->getTable()
            ->when(! is_null($queue), fn ($query) => $query->where('queue', $queue))
            ->orderBy('id', 'desc')
            ->values('id');
    }

    /**
     * Get a list of all of the failed jobs.
     */
    public function all(): array
    {
        return $this->getTable()->orderBy('id', 'desc')->all();
    }

    /**
     * Get a single failed job.
     */
    public function find(string|int $id): ?object
    {
        return $this->getTable()->where($this->whereId($id))->first();
    }

    /**
     * Delete a single failed job from storage.
     */
    public function forget(string|int $id): bool
    {
        return $this->getTable()->where($this->whereId($id))->delete() > 0;
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

    private function whereId(string|int $id): array
    {
        return [is_string($id) && strlen($id) === 32 ? 'uuid' : 'id' => $id];
    }

    private function insertGetId(array $data): ?int
    {
        ($builder = $this->getTable())->insert($data);

        return $builder->db()->lastId($this->table);
    }
}
