<?php

namespace BlitzPHP\Queue\Jobs;

use BlitzPHP\Contracts\Queue\Job as JobContract;
use BlitzPHP\Utilities\String\Text;
use DateInterval;
use DateTimeInterface;
use Throwable;

/**
 * Job factice utilisé pour tester les interactions avec la file (delete, fail, release).
 */
class FakeJob extends Job implements JobContract
{
    /**
     * Délai (secondes) avec lequel le job a été relâché.
     *
     * @var int
     */
    public $releaseDelay;

    /**
     * Nombre de tentatives de traitement du job.
     */
    public int $attempts = 1;

    /**
     * Exception ayant provoqué l'échec du job.
     *
     * @var \Throwable
     */
    public $failedWith;

    /**
     * Retourne l'identifiant du job.
     */
    public function getJobId(): string
    {
		return (string) Text::uuid();
    }

    /**
     * Retourne le corps brut (JSON) du job.
     */
    public function getRawBody(): string
    {
        return '';
    }

    /**
     * Relâche le job dans la file après n secondes.
     */
    public function release(DateTimeInterface|DateInterval|int $delay = 0): void
    {
        $this->released = true;
        $this->releaseDelay = $delay;
    }

    /**
     * Retourne le nombre de tentatives déjà effectuées.
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * Supprime le job de la file.
     */
    public function delete(): void
    {
        $this->deleted = true;
    }

    /**
     * Supprime le job, appelle `failed()` et émet l'événement d'échec.
     */
    public function fail(?Throwable $e = null): void
    {
        $this->failed = true;
        $this->failedWith = $e;
    }
}
