<?php

namespace BlitzPHP\Queue\Failed;

use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Utilities\Date;
use DateTimeInterface;
use Throwable;

/**
 * Stocke les jobs échoués en base, identifiés par l'UUID du payload.
 */
class DatabaseUuidFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
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
     * Retourne les identifiants de tous les jobs échoués.
     */
    public function ids(?string $queue = null): array
    {
        return $this->getTable()
            ->when(! is_null($queue), fn ($query) => $query->where('queue', $queue))
            ->orderBy('id', 'desc')
            ->values('uuid');
    }

    /**
     * Retourne la liste de tous les jobs échoués.
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
     * Retourne un job échoué.
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
     * Supprime un job échoué du stockage.
     */
    public function forget(string|int $id): bool
    {
        return $this->getTable()->where('uuid', $id)->delete() > 0;
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
}
