<?php

namespace BlitzPHP\Queue\Jobs;

use BlitzPHP\Traits\Support\InteractsWithTime;

/**
 * Enveloppe d'une ligne SQL représentant un job en file d'attente.
 */
class DatabaseJobRecord
{
    use InteractsWithTime;

    /**
     * Crée une instance d'enregistrement de job.
     *
     * @param  \stdClass  $record Enregistrement sous-jacent du job.
     */
    public function __construct(protected \stdClass $record)
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
