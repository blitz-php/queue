<?php
namespace BlitzPHP\Queue\Failed;

use Throwable;

/**
 * Contrat de persistance des jobs définitivement échoués.
 */
interface FailedJobProviderInterface
{
    /**
     * Enregistre un job échoué dans le stockage.
     *
     * @return string|int|null
     */
    public function log(string $connection, string $queue, string $payload, Throwable $exception): string|int|null;

    /**
     * Retourne les identifiants de tous les jobs échoués.
     *
     * @return array<int, string|int>
     */
    public function ids(?string $queue = null): array;

    /**
     * Retourne la liste de tous les jobs échoués.
     *
     * @return array<object>
     */
    public function all(): array;

    /**
     * Retourne un job échoué.
     */
    public function find(string|int $id): ?object;

    /**
     * Supprime un job échoué du stockage.
     */
    public function forget(string|int $id): bool;

    /**
     * Vide le stockage des jobs échoués.
     */
    public function flush(?int $hours = null): void;
}
