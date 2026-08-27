<?php

namespace BlitzPHP\Queue\Drivers;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Contracts\Queue\Queue as QueueContract;
use BlitzPHP\Queue\Queue;
use BlitzPHP\Utilities\Iterable\Collection;
use DateInterval;
use DateTimeInterface;

/**
 * Pilote nul : accepte les jobs sans les stocker ni les exécuter.
 */
class NullDriver extends Queue implements QueueContract, ConnectorInterface
{
    /**
     * Établit une connexion de file d'attente.
     */
    public static function connect(ContainerInterface $container, array $config): QueueContract
    {
        return new self;
    }

    /**
     * Retourne le nombre total de jobs dans la file.
     */
    public function size(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Retourne le nombre de jobs en attente.
     */
    public function pendingSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Retourne le nombre de jobs retardés.
     */
    public function delayedSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Retourne le nombre de jobs réservés.
     */
    public function reservedSize(?string $queue = null): int
    {
        return 0;
    }

    /**
     * Retourne les jobs en attente de la file donnée.
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Retourne les jobs retardés de la file donnée.
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Retourne les jobs réservés de la file donnée.
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return new Collection;
    }

    /**
     * Retourne l'horodatage de création du plus ancien job en attente (hors retardés).
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return null;
    }

    /**
     * Envoie un nouveau job dans la file.
     */
    public function push(string|object $job, mixed $data = '', ?string $queue = null): mixed
    {
        return null;
    }

    /**
     * Envoie un payload brut dans la file.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return null;
    }

    /**
     * Envoie un job dans la file après n secondes.
     */
    public function later(DateTimeInterface|DateInterval|int $delay, string|object $job, mixed $data = '', ?string $queue = null): mixed
    {
        return null;
    }

    /**
     * Prélève le prochain job de la file.
     */
    public function pop(?string $queue = null): ?Job
    {
        return null;
    }
}
