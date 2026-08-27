<?php

namespace BlitzPHP\Queue\Failed;

use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Utilities\Date;
use DateTimeInterface;
use Throwable;

/**
 * Stocke les jobs échoués en base, identifiés par une clé auto-incrémentée.
 */
class DatabaseFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    /**
     * Crée un fournisseur de jobs échoués en base de données.
     *
     * @param  ConnectionResolverInterface  $resolver Résolveur de connexions base de données.
     * @param  string  $database Nom de la connexion base de données.
     * @param  string  $table Nom de la table.
     */
    public function __construct(protected ConnectionResolverInterface $resolver, protected string $database, protected string $table)
    {
    }

    /**
     * Enregistre un job échoué dans le stockage.
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
     * Retourne les identifiants de tous les jobs échoués.
     */
    public function ids(?string $queue = null): array
    {
        return $this->getTable()
            ->when(! is_null($queue), fn ($query) => $query->where('queue', $queue))
            ->orderBy('id', 'desc')
            ->values('id');
    }

    /**
     * Retourne la liste de tous les jobs échoués.
     */
    public function all(): array
    {
        return $this->getTable()->orderBy('id', 'desc')->all();
    }

    /**
     * Retourne un job échoué.
     */
    public function find(string|int $id): ?object
    {
        return $this->getTable()->where($this->whereId($id))->first();
    }

    /**
     * Supprime un job échoué du stockage.
     */
    public function forget(string|int $id): bool
    {
        return $this->getTable()->where($this->whereId($id))->delete() > 0;
    }

    /**
     * Vide le stockage des jobs échoués.
     */
    public function flush(?int $hours = null): void
    {
        $this->getTable()->when($hours, function ($query, $hours) {
            $query->where('failed_at <=', Date::now()->subHours($hours)->format('Y-m-d H:i:s'));
        })->delete();
    }

    /**
     * Purge les entrées antérieures à la date donnée.
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
     * Compte les jobs échoués.
     */
    public function count(?string $connection = null, ?string $queue = null): int
    {
        return $this->getTable()
            ->when($connection, fn ($builder) => $builder->where('connection', $connection))
            ->when($queue, fn ($builder) => $builder->where('queue', $queue))
            ->count();
    }

    /**
     * Retourne un constructeur de requêtes pour la table.
     * 
     * @return BaseBuilder
     */
    public function getTable()
    {
        return $this->resolver->connection($this->database)->table($this->table);
    }

    /**
     * Clause WHERE selon que l'identifiant est un UUID (32 caractères) ou un entier.
     *
     * @return array<string, string|int>
     */
    private function whereId(string|int $id): array
    {
        return [is_string($id) && strlen($id) === 32 ? 'uuid' : 'id' => $id];
    }

    /**
     * Insère une ligne et retourne l'identifiant généré.
     */
    private function insertGetId(array $data): ?int
    {
        ($builder = $this->getTable())->insert($data);

        return $builder->db()->lastId($this->table);
    }
}
