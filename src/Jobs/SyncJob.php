<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Jobs;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Queue\Job as JobContract;

/**
 * Job exécuté immédiatement par le pilote synchrone (sans persistance).
 */
class SyncJob extends Job implements JobContract
{
    /**
     * Nom de classe du job.
     *
     * @var string
     */
    protected $job;

    /**
     * Crée une nouvelle instance de job.
     *
     * @param string $payload Données du message de file.
     */
    public function __construct(ContainerInterface $container, protected string $payload, string $connectionName, string $queue)
    {
        $this->queue          = $queue;
        $this->container      = $container;
        $this->connectionName = $connectionName;
    }

    /**
     * Relâche le job dans la file après n secondes.
     */
    public function release(int $delay = 0): void
    {
        parent::release($delay);
    }

    /**
     * Retourne le nombre de tentatives déjà effectuées.
     */
    public function attempts(): int
    {
        return 1;
    }

    /**
     * Retourne l'identifiant du job.
     */
    public function getJobId(): string
    {
        return '';
    }

    /**
     * Retourne le corps brut du job sous forme de chaîne.
     */
    public function getRawBody(): string
    {
        return $this->payload;
    }

    /**
     * Retourne le nom de la file du job.
     */
    public function getQueue(): string
    {
        return 'sync';
    }
}
