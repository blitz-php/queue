<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Failed;

use Throwable;

/**
 * Fournisseur vide : n'enregistre aucun job échoué.
 */
class NullFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface
{
    /**
     * {@inheritDoc}
     */
    public function log(string $connection, string $queue, string $payload, Throwable $exception): int|string|null
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function ids(?string $queue = null): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function all(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function find(int|string $id): ?object
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function forget(int|string $id): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function flush(?int $hours = null): void
    {
        // Ne rien faire
    }

    /**
     * {@inheritDoc}
     */
    public function count(?string $connection = null, ?string $queue = null): int
    {
        return 0;
    }
}
