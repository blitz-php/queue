<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Enums;

/**
 * Motifs d'arrêt d'un worker de file d'attente.
 */
enum WorkerStopReason: string
{
    /**
     * Interruption par signal (SIGINT, SIGTERM, etc.).
     */
    case Interrupted = 'interrupted';
    /**
     * Perte de connexion (base de données, courtier, etc.).
     */
    case LostConnection = 'lost_connection';
    /**
     * Nombre maximal de jobs atteint.
     */
    case MaxJobsExceeded = 'max_jobs';
    /**
     * Limite mémoire dépassée.
     */
    case MaxMemoryExceeded = 'memory';
    /**
     * Durée de vie maximale du worker atteinte.
     */
    case MaxTimeExceeded = 'max_time';
    /**
     * File vide et option `stopWhenEmpty` active.
     */
    case QueueEmpty = 'empty';
    /**
     * Signal de redémarrage reçu via le cache.
     */
    case ReceivedRestartSignal = 'restart_signal';
    /**
     * Dépassement du délai d'exécution d'un job.
     */
    case TimedOut = 'timed_out';
}
