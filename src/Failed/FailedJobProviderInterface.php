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
 * Contrat de persistance des jobs définitivement échoués.
 */
interface FailedJobProviderInterface
{
    /**
     * Enregistre un job échoué dans le stockage.
     */
    public function log(string $connection, string $queue, string $payload, Throwable $exception): int|string|null;

    /**
     * Retourne les identifiants de tous les jobs échoués.
     *
     * @return array<int, int|string>
     */
    public function ids(?string $queue = null): array;

    /**
     * Retourne la liste de tous les jobs échoués.
     *
     * @return list<object>
     */
    public function all(): array;

    /**
     * Retourne un job échoué.
     */
    public function find(int|string $id): ?object;

    /**
     * Supprime un job échoué du stockage.
     */
    public function forget(int|string $id): bool;

    /**
     * Vide le stockage des jobs échoués.
     */
    public function flush(?int $hours = null): void;
}
