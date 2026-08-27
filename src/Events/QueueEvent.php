<?php

namespace BlitzPHP\Queue\Events;

use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Event\Event;
use BlitzPHP\Utilities\Date;
use BlitzPHP\Utilities\String\Text;
use Throwable;

/**
 * Événement du cycle de vie de la file d'attente (job, worker, connexion, opération).
 *
 * @property mixed      $job
 * @property ?int       $jobId
 * @property ?int       $attempts
 * @property ?Throwable $exception
 */
class QueueEvent extends Event
{
    /**
     * Instant de survenue de l'événement.
     */
    private readonly Date $timestamp;

    /**
     * @param string               $type       Identifiant de l'événement (constantes de QueueEventManager).
     * @param string               $connection Nom de la connexion concernée.
     * @param string|null          $queue      Nom de la file, le cas échéant.
     * @param array<string, mixed> $metadata   Données contextuelles (job, exception, etc.).
     * @param Date|null            $timestamp  Horodatage (maintenant par défaut).
     */
    public function __construct(
        public readonly string $type,
        public readonly string $connection,
        public readonly ?string $queue = null,
        private readonly array $metadata = [],
        ?Date $timestamp = null,
    ) {
		parent::__construct($this->type);

        $this->timestamp = $timestamp ?? Date::now();
    }

    /**
     * Retourne l'horodatage de l'événement.
     */
    public function timestamp(): Date
    {
        return $this->timestamp;
    }

    /**
     * Retourne l'ensemble des métadonnées.
     */
    public function allMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Retourne une métadonnée par sa clé.
     */
    public function metadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Indique s'il s'agit d'un événement lié à un job.
     */
    public function isJobEvent(): bool
    {
        return str_starts_with($this->type, 'queue.job.');
    }

    /**
     * Indique s'il s'agit d'un événement lié au worker.
     */
    public function isWorkerEvent(): bool
    {
        return str_starts_with($this->type, 'queue.worker.');
    }

    /**
     * Indique s'il s'agit d'un événement d'opération (ex. file vidée).
     */
    public function isOperationEvent(): bool
    {
        return str_contains($this->type, 'queue.')
               && ! $this->isJobEvent()
               && ! $this->isWorkerEvent()
               && ! $this->isConnectionEvent();
    }

    /**
     * Indique s'il s'agit d'un événement de connexion.
     */
    public function isConnectionEvent(): bool
    {
        return str_starts_with($this->type, 'queue.connection.');
    }

    /**
     * Retourne l'identifiant du job (événements de job).
     */
    public function getJobId(): ?int
    {
        $job = $this->job;

        return $job instanceof Job ? $job->getJobId() : $this->metadata('job_id');
    }

    /**
     * Retourne le nombre de tentatives (événements de job).
     */
    public function getAttempts(): ?int
    {
        $job = $this->job;

        return $job instanceof Job ? $job->attempts() : $this->metadata('attempts');
    }

    /**
     * Retourne le statut du job (événements de job).
     */
    public function getStatus(): ?int
    {
        $job = $this->job;

        return $job instanceof Job ? $job->status : $this->metadata('status');
    }

    /**
     * Retourne le nom de classe du job (événements de job).
     */
    public function getJobClass(): ?string
    {
        return $this->metadata('job_class');
    }

    /**
     * Retourne le temps de traitement en secondes.
     */
    public function getProcessingTime(): float
    {
        return (float) $this->metadata('processing_time', 0.0);
    }

    /**
     * Retourne le temps de traitement en millisecondes.
     */
    public function getProcessingTimeMs(): int
    {
        return (int) ($this->getProcessingTime() * 1000);
    }

    /**
     * Retourne l'exception (événements d'échec).
     */
    public function getException(): ?Throwable
    {
        return $this->metadata('exception') ?? $this->metadata('e');
    }

    /**
     * Retourne le message d'exception (événements d'échec).
     */
    public function getExceptionMessage(): ?string
    {
        return $this->getException()?->getMessage();
    }

    /**
     * Indique si l'événement correspond à un échec.
     */
    public function hasFailed(): bool
    {
		$job = $this->job;

		return $job instanceof Job ? $job->hasFailed() : $this->getException() !== null;
    }

    /**
     * Convertit l'événement en tableau pour sérialisation.
     */
    public function toArray(): array
    {
        return [
            'type'      => $this->type,
            'connection'   => $this->connection,
            'queue'     => $this->queue,
            'metadata'  => $this->metadata,
            'timestamp' => $this->timestamp->toDateTimeString(),
        ];
    }

    /**
     * Accès magique aux métadonnées et accesseurs `get*`.
     */
    public function __get(string $name): mixed
    {
        if (method_exists($this, $method = 'get' . Text::camel($name))) {
            return $this->{$method}();
        }

        return $this->metadata($name);
    }
}
