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
use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Queue\Events\QueueEventManager;
use BlitzPHP\Queue\Exceptions\ManuallyFailedException;
use BlitzPHP\Queue\Exceptions\TimeoutExceededException;
use BlitzPHP\Traits\Support\InteractsWithTime;
use Throwable;

/**
 * Représentation d'un job prélevé d'une file d'attente.
 *
 * Encapsule le payload, le cycle de vie (exécution, suppression, relâchement,
 * échec) et les métadonnées (tentatives, timeout, backoff).
 */
abstract class Job
{
    use InteractsWithTime;

    /**
     * Instance du handler de job résolu.
     *
     * @var mixed
     */
    protected $instance;

    /**
     * Conteneur d'injection de dépendances.
     */
    protected ContainerInterface $container;

    /**
     * Indique si le job a été supprimé de la file.
     */
    protected bool $deleted = false;

    /**
     * Indique si le job a été relâché dans la file.
     */
    protected bool $released = false;

    /**
     * Indique si le job a été marqué en échec.
     */
    protected bool $failed = false;

    /**
     * Nom de la connexion à laquelle appartient le job.
     */
    protected string $connectionName;

    /**
     * Nom de la file à laquelle appartient le job.
     */
    protected string $queue;

    /**
     * Retourne l'identifiant du job.
     */
    abstract public function getJobId(): int|string|null;

    /**
     * Retourne le corps brut (JSON) du job.
     */
    abstract public function getRawBody(): string;

    /**
     * Retourne l'UUID du job.
     */
    public function uuid(): ?string
    {
        return $this->payload()['uuid'] ?? null;
    }

    /**
     * Déclenche l'exécution du job.
     */
    public function fire(): void
    {
        $payload = $this->payload();

        [$class, $method] = JobName::parse($payload['job']);

        ($this->instance = $this->resolve($class))->{$method}($this, $payload['data']);
    }

    /**
     * Supprime le job de la file.
     */
    public function delete(): void
    {
        $this->deleted = true;
    }

    /**
     * Indique si le job a été supprimé.
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * Relâche le job dans la file après n secondes.
     */
    public function release(int $delay = 0): void
    {
        $this->released = true;
    }

    /**
     * Indique si le job a été relâché dans la file.
     */
    public function isReleased(): bool
    {
        return $this->released;
    }

    /**
     * Indique si le job a été supprimé ou relâché.
     */
    public function isDeletedOrReleased(): bool
    {
        return $this->isDeleted() || $this->isReleased();
    }

    /**
     * Indique si le job a été marqué en échec.
     */
    public function hasFailed(): bool
    {
        return $this->failed;
    }

    /**
     * Marque le job comme échoué.
     */
    public function markAsFailed(): void
    {
        $this->failed = true;
    }

    /**
     * Supprime le job, appelle `failed()` et émet l'événement d'échec.
     */
    public function fail(?Throwable $e = null): void
    {
        $this->markAsFailed();

        if ($this->isDeleted()) {
            return;
        }

        if ($this->shouldRollBackDatabaseTransaction($e)) {
            $this->container->get(ConnectionResolverInterface::class)
                ->connection(config('queue.failed.database'))
                ->rollBack();
        }

        try {
            // En cas d'échec : suppression, appel de failed(), puis événement
            // pour permettre le suivi et la journalisation des jobs échoués.
            $this->delete();

            $this->failed($e);
        } finally {
            $this->resolve(QueueEventManager::class)->jobFailed($this->connectionName, $this, $e ?: new ManuallyFailedException());
        }
    }

    /**
     * Indique si la transaction SQL courante doit être annulée jusqu'au niveau zéro.
     */
    protected function shouldRollBackDatabaseTransaction(Throwable $e): bool
    {
        $config = config('queue.failed');

        return $e instanceof TimeoutExceededException
            && $config['database']
            && in_array($config['driver'], ['database', 'database-uuids'], true)
            && $this->container->bound(ConnectionResolverInterface::class);
    }

    /**
     * Traite l'exception à l'origine de l'échec du job.
     */
    protected function failed(?Throwable $e): void
    {
        $payload = $this->payload();

        [$class] = JobName::parse($payload['job']);

        if (method_exists($this->instance = $this->resolve($class), 'failed')) {
            $this->instance->failed($payload['data'], $e, $payload['uuid'] ?? '', $this);
        }
    }

    /**
     * Résout la classe donnée via le conteneur.
     */
    protected function resolve(string $class): mixed
    {
        return $this->container->make($class);
    }

    /**
     * Retourne l'instance du handler déjà résolue.
     */
    public function getResolvedJob(): mixed
    {
        return $this->instance;
    }

    /**
     * Retourne le corps du job décodé (tableau).
     */
    public function payload(): array
    {
        return json_decode($this->getRawBody(), true);
    }

    /**
     * Retourne le nombre maximal de tentatives du job.
     */
    public function maxTries(): ?int
    {
        return $this->payload()['maxTries'] ?? null;
    }

    /**
     * Retourne le nombre maximal d'exceptions avant échec définitif.
     */
    public function maxExceptions(): ?int
    {
        return $this->payload()['maxExceptions'] ?? null;
    }

    /**
     * Indique si le job doit échouer en cas de dépassement de délai.
     */
    public function shouldFailOnTimeout(): bool
    {
        return $this->payload()['failOnTimeout'] ?? false;
    }

    /**
     * Secondes d'attente avant de relancer un job ayant levé une exception non gérée.
     *
     * @return int|list<int>|null
     */
    public function backoff()
    {
        return $this->payload()['backoff'] ?? $this->payload()['delay'] ?? null;
    }

    /**
     * Retourne la durée maximale d'exécution du job (secondes).
     */
    public function timeout(): ?int
    {
        return $this->payload()['timeout'] ?? null;
    }

    /**
     * Retourne l'horodatage limite au-delà duquel le job ne doit plus être retenté.
     */
    public function retryUntil(): ?int
    {
        return $this->payload()['retryUntil'] ?? null;
    }

    /**
     * Retourne le nom du handler de job enfilé.
     */
    public function getName(): string
    {
        return $this->payload()['job'];
    }

    /**
     * Retourne le nom d'affichage résolu du job.
     *
     * Résout le nom des jobs « enveloppés » (handlers de classe).
     */
    public function resolveName(): string
    {
        return JobName::resolve($this->getName(), $this->payload());
    }

    /**
     * Retourne la classe du job enfilé.
     *
     * Résout la classe des jobs « enveloppés » (handlers de classe).
     */
    public function resolveQueuedJobClass(): string
    {
        return JobName::resolveClassName($this->getName(), $this->payload());
    }

    /**
     * Retourne le nom de la connexion du job.
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Retourne le nom de la file du job.
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * Retourne le conteneur de services.
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }
}
