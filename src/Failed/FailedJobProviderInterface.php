<?php
namespace BlitzPHP\Queue\Failed;

use Throwable;

interface FailedJobProviderInterface
{
    /**
     * Log a failed job into storage.
     *
     * @return string|int|null
     */
    public function log(string $connection, string $queue, string $payload, Throwable $exception): string|int|null;

    /**
     * Get the IDs of all of the failed jobs.
     *
     * @return array<int, string|int>
     */
    public function ids(?string $queue = null): array;

    /**
     * Get a list of all of the failed jobs.
     *
     * @return array<object>
     */
    public function all(): array;

    /**
     * Get a single failed job.
     */
    public function find(string|int $id): ?object;

    /**
     * Delete a single failed job from storage.
     */
    public function forget(string|int $id): bool;

    /**
     * Flush all of the failed jobs from storage.
     */
    public function flush(?int $hours = null): void;
}
