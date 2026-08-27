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

use BlitzPHP\Traits\Support\InteractsWithTime;
use stdClass;

/**
 * Enveloppe d'une ligne SQL représentant un job en file d'attente.
 */
class DatabaseJobRecord
{
    use InteractsWithTime;

    /**
     * Crée une instance d'enregistrement de job.
     *
     * @param stdClass $record Enregistrement sous-jacent du job.
     */
    public function __construct(protected stdClass $record)
    {
    }

    /**
     * Incrémente le nombre de tentatives du job.
     */
    public function increment(): int
    {
        $this->record->attempts++;

        return $this->record->attempts;
    }

    /**
     * Met à jour l'horodatage de réservation du job.
     */
    public function touch(): int
    {
        $this->record->reserved_at = $this->currentTime();

        return $this->record->reserved_at;
    }

    /**
     * Accède dynamiquement aux champs de l'enregistrement.
     */
    public function __get(string $key): mixed
    {
        return $this->record->{$key};
    }
}
