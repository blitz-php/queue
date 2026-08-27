<?php

namespace BlitzPHP\Queue;

use BlitzPHP\Queue\Traits\Dispatchable;
use BlitzPHP\Queue\Traits\InteractsWithQueue;
use BlitzPHP\Queue\Traits\SerializesModels;

/**
 * Classe de base des jobs métier destinés à la file d'attente.
 *
 * Étendez cette classe et implémentez `handle()` pour définir le travail
 * à exécuter. Les traits associés permettent le dispatch, l'interaction
 * avec le worker et la sérialisation des modèles.
 */
abstract class Job
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * Nombre maximal de tentatives avant échec définitif.
     */
    protected int $maxTries = 3;

    /**
     * Délai en secondes avant une nouvelle tentative après une exception.
     */
    protected int $backoff = 60;

    /**
     * Nom de la file logique sur laquelle dispatcher le job (vide = file par défaut).
     */
    protected string $queue = '';

    /**
     * Retourne le nombre maximal de tentatives autorisées.
     */
    public function maxTries(): int
    {
        return $this->maxTries;
    }

    /**
     * Retourne le délai d'attente (en secondes) avant retry.
     */
    public function backoff(): int
    {
        return $this->backoff;
    }

    /**
     * Retourne le nom de la file cible du job.
     */
    public function queue(): string
    {
        return $this->queue;
    }
}
