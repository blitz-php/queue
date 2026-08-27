<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\DTO;

/**
 * Options d'exécution d'un worker de file d'attente.
 *
 * Ces valeurs sont généralement renseignées par la commande `queue:work`
 * et contrôlent la durée de vie, les limites et le comportement du processus.
 */
class WorkerOptions
{
    /**
     * Crée une instance d'options du worker.
     *
     * @param string        $name          Nom du worker (utilisé pour les callbacks de pop personnalisés).
     * @param int|list<int> $backoff       Secondes d'attente avant de relancer un job ayant levé une exception non gérée.
     * @param int           $memory        Mémoire maximale autorisée (Mo) avant arrêt du worker.
     * @param int           $timeout       Durée maximale d'exécution d'un job enfant (secondes).
     * @param int           $sleep         Secondes d'attente entre deux sondages lorsque la file est vide.
     * @param int           $maxTries      Nombre maximal de tentatives par job.
     * @param bool          $force         Si `true`, le worker tourne même en mode maintenance.
     * @param bool          $stopWhenEmpty Si `true`, le worker s'arrête dès que la file est vide.
     * @param int           $maxJobs       Nombre maximal de jobs à traiter (0 = illimité).
     * @param int           $maxTime       Durée de vie maximale du worker en secondes (0 = illimitée).
     * @param int           $rest          Secondes de pause entre deux jobs traités avec succès.
     */
    public function __construct(
        public string $name = 'default',
        public array|int $backoff = 0,
        public int $memory = 128,
        public int $timeout = 60,
        public int $sleep = 3,
        public int $maxTries = 1,
        public bool $force = false,
        public bool $stopWhenEmpty = false,
        public int $maxJobs = 0,
        public int $maxTime = 0,
        public $rest = 0,
    ) {
    }
}
