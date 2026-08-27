<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Models;

use BlitzPHP\Contracts\Database\ConnectionInterface;
use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Database\Connection\BaseConnection;
use BlitzPHP\Database\Model;
use BlitzPHP\Traits\Support\InteractsWithTime;
use BlitzPHP\Utilities\Date;
use Throwable;

/**
 * Modèle des lignes de la table des jobs en file d'attente.
 */
class JobModel extends Model
{
    use InteractsWithTime;

    /**
     * Format de stockage des dates (horodatage Unix).
     */
    protected string $dateFormat = 'int';

    /**
     * Désactive les callbacks du modèle pendant les opérations de file.
     */
    protected bool $allowCallbacks = false;

    /**
     * Délai d'expiration d'un job réservé (secondes).
     */
    protected ?int $retryAfter = 60;

    /**
     * @param array<string, mixed>        $config   Configuration de la connexion `database`.
     * @param ConnectionResolverInterface $resolver Résolveur de connexions.
     * @param ConnectionInterface         $db       Connexion SQL utilisée.
     */
    public function __construct(array $config, protected ConnectionResolverInterface $resolver, ConnectionInterface $db)
    {
        assert($db instanceof BaseConnection);

        $this->table      = $config['table'];
        $this->retryAfter = $config['retry_after'] ?? 60;

        // Désactive le mode transaction strict
        $db->transStrict(false);

        parent::__construct($resolver, $db);
    }

    /**
     * Retourne le nombre total de jobs dans la file.
     */
    public function size(string $queue): int
    {
        return $this->builder()
            ->where('queue', $queue)
            ->count();
    }

    /**
     * Retourne le nombre de jobs en attente.
     */
    public function pendingSize(string $queue): int
    {
        return $this->builder()
            ->where('queue', $queue)
            ->where('available_at <=', $this->currentTime())
            ->whereNull('reserved_at')
            ->count();
    }

    /**
     * Retourne le nombre de jobs retardés.
     */
    public function delayedSize(string $queue): int
    {
        return $this->builder()
            ->where('queue', ${$queue})
            ->where('available_at >', $this->currentTime())
            ->whereNull('reserved_at')
            ->count();
    }

    /**
     * Retourne le nombre de jobs réservés.
     */
    public function reservedSize(string $queue): int
    {
        return $this->builder()
            ->where('queue', ${$queue})
            ->whereNotNull('reserved_at')
            ->count();
    }

    /**
     * Retourne les jobs en attente de la file donnée.
     */
    public function pendingJobs(string $queue): array
    {
        return $this->builder()
            ->where('queue', $queue)
            ->where('available_at <=', $this->currentTime())
            ->whereNull('reserved_at')
            ->all();
    }

    /**
     * Retourne les jobs retardés de la file donnée.
     */
    public function delayedJobs(string $queue): array
    {
        return $this->builder()
            ->where('queue', $queue)
            ->where('available_at >', $this->currentTime())
            ->whereNull('reserved_at')
            ->all();
    }

    /**
     * Retourne les jobs réservés de la file donnée.
     */
    public function reservedJobs(string $queue): array
    {
        return $this->builder()
            ->where('queue', $queue)
            ->whereNotNull('reserved_at')
            ->all();
    }

    /**
     * Retourne l'horodatage de création du plus ancien job en attente (hors retardés).
     */
    public function creationTimeOfOldestPendingJob(string $queue): ?int
    {
        return $this->builder()
            ->where('queue', $queue)
            ->where('available_at <=', $this->currentTime())
            ->whereNull('reserved_at')
            ->sortAsc('available_at')
            ->value('available_at');
    }

    /**
     * Insère un payload brut en base avec un délai de n secondes.
     */
    public function pushToDatabase(array $data): mixed
    {
        $this->builder()->insert($data);

        return $this->db->lastId($this->table);
    }

    /**
     * Retourne le prochain job disponible de la file.
     */
    public function getNextAvailableJob(string $queue): ?object
    {
        return $this->builder()
            // ->lock($this->getLockForPopping()) disponible uniquement avec blitz-php/database > 1.2
            ->where('queue', $queue)
            ->where(function ($query) {
                $this->isAvailable($query);
                $this->isReservedButExpired($query);
            })
            ->orderBy('id', 'asc')
            ->first();
    }

    /**
     * Supprime un job réservé de la file.
     *
     * @throws Throwable
     */
    public function deleteReserved(string $queue, string $id): void
    {
        $this->db->transaction(function () use ($id) {
            if ($this/* ->lockForUpdate() */ ->where('id', $id)->first()) {
                $this->where('id', $id)->delete();
            }
        });
    }

    /**
     * Supprime tous les jobs de la file.
     */
    public function clear(string $queue): bool
    {
        $this->builder()->where('queue', $queue)->delete();

        return true;
    }

    /**
     * Restreint la requête aux jobs disponibles.
     */
    protected function isAvailable(BaseBuilder $query): void
    {
        $query->where(function ($query) {
            $query->whereNull('reserved_at')
                ->where('available_at <=', $this->currentTime());
        });
    }

    /**
     * Inclut les jobs réservés dont le verrou a expiré.
     */
    protected function isReservedButExpired(BaseBuilder $query): void
    {
        $expiration = Date::now()->subSeconds($this->retryAfter)->getTimestamp();

        $query->orWhere(function ($query) use ($expiration) {
            $query->where('reserved_at <=', $expiration);
        });
    }
}
