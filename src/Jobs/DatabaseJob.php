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
use BlitzPHP\Queue\Drivers\DatabaseDriver;

/**
 * Job persisté et prélevé via le pilote base de données.
 */
class DatabaseJob extends Job implements JobContract
{
    /**
     * Crée une nouvelle instance de job.
     *
     * @param DatabaseDriver    $database Instance du pilote base de données.
     * @param DatabaseJobRecord $job      Enregistrement / payload du job en base.
     */
    public function __construct(ContainerInterface $container, protected DatabaseDriver $database, protected DatabaseJobRecord $job, string $connectionName, string $queue)
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

        $this->database->deleteAndRelease($this->queue, $this, $delay);
    }

    /**
     * Supprime le job de la file.
     */
    public function delete(): void
    {
        parent::delete();

        $this->database->deleteReserved($this->queue, $this->job->id);
    }

    /**
     * Retourne le nombre de tentatives déjà effectuées.
     */
    public function attempts(): int
    {
        return (int) $this->job->attempts;
    }

    /**
     * Retourne l'identifiant du job.
     */
    public function getJobId(): string
    {
        return (string) $this->job->id;
    }

    /**
     * Retourne le corps brut du job sous forme de chaîne.
     */
    public function getRawBody(): string
    {
        return $this->job->payload;
    }

    /**
     * Retourne l'enregistrement SQL du job.
     */
    public function getJobRecord(): DatabaseJobRecord
    {
        return $this->job;
    }
}
