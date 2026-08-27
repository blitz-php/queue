<?php

namespace BlitzPHP\Queue;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Contracts\Queue\Queue as QueueContract;
use BlitzPHP\Contracts\Security\EncrypterInterface;
use BlitzPHP\Queue\Events\QueueEventManager;
use BlitzPHP\Queue\Exceptions\InvalidPayloadException;
use BlitzPHP\Traits\Support\InteractsWithTime;
use BlitzPHP\Utilities\Date;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Utilities\String\Uuid;
use Closure;
use DateInterval;
use DateTimeInterface;
use RuntimeException;
use Throwable;

/**
 * Classe de base des connexions de file d'attente.
 *
 * Encapsule la création du payload JSON, les hooks de sérialisation,
 * le dispatch et l'émission des événements d'enfilement.
 */
abstract class Queue implements QueueContract
{
    use InteractsWithTime;

    /**
     * Conteneur d'injection de dépendances.
     */
    protected ContainerInterface $container;

    /**
     * Gestionnaire d'événements de la file.
     */
    protected ?QueueEventManager $eventManager = null;

    /**
     * Nom de la connexion (clé de `queue.connections`).
     */
    protected string $connectionName = '';

    /**
     * Configuration brute de la connexion.
     */
    protected array $config;

    /**
     * Indique si les jobs doivent être envoyés après le commit des transactions SQL.
     */
    protected bool $dispatchAfterCommit;

    /**
     * Callbacks exécutés lors de la construction du payload.
     *
     * @var callable[]
     */
    protected static array $createPayloadCallbacks = [];

    /**
     * Envoie un job sur une file nommée.
     */
    public function pushOn(string $queue, string|object $job, mixed $data = ''): mixed
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Envoie un job sur une file nommée, avec un délai en secondes.
     */
    public function laterOn(string $queue, DateTimeInterface|DateInterval|int $delay, string|object $job, mixed $data = ''): mixed
    {
        return $this->later($delay, $job, $data, $queue);
    }

    /**
     * Envoie plusieurs jobs sur la file.
     *
     * @param array<int, string|Job> $jobs
     *
     * @return void
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null)
    {
        foreach ($jobs as $job) {
            $this->push($job, $data, $queue);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clear(string $queue): bool
    {
        return true;
    }

    /**
     * Construit la chaîne JSON du payload à partir du job et des données.
     *
     * @throws InvalidPayloadException Si l'encodage JSON échoue.
     */
    protected function createPayload(string|object $job, string $queue, mixed $data = '', DateTimeInterface|DateInterval|int|null $delay = null): ?string
    {
        if ($job instanceof Closure) {
            $job = CallQueuedClosure::create($job);
        }

        $value = $this->createPayloadArray($job, $queue, $data);

        $value['delay'] = isset($delay)
            ? $this->secondsUntil($delay)
            : null;

        $payload = json_encode($value, \JSON_UNESCAPED_UNICODE);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidPayloadException(
                'Unable to JSON encode payload. Error ('.json_last_error().'): '.json_last_error_msg(), $value
            );
        }

        return $payload;
    }

    /**
     * Construit le tableau de payload (objet métier ou handler sous forme de chaîne).
     */
    protected function createPayloadArray(string|object $job, string $queue, mixed $data = ''): array
    {
        return is_object($job)
            ? $this->createObjectPayload($job, $queue)
            : $this->createStringPayload($job, $queue, $data);
    }

    /**
     * Construit le payload d'un handler objet (job sérialisé, éventuellement chiffré).
     *
     * @throws RuntimeException Si la sérialisation du job échoue.
     */
    protected function createObjectPayload(object $job, string $queue): array
    {
        $payload = $this->withCreatePayloadHooks($queue, [
            'uuid' => (string) Uuid::v4(),
            'displayName' => $this->getDisplayName($job),
            'job' => 'BlitzPHP\Queue\CallQueuedHandler@call',
            'maxTries' => $this->getJobTries($job),
            'maxExceptions' => $job->maxExceptions ?? null,
            'failOnTimeout' => $job->failOnTimeout ?? false,
            'backoff' => $this->getJobBackoff($job),
            'timeout' => $job->timeout ?? null,
            'retryUntil' => $this->getJobExpiration($job),
            'deleteWhenMissingModels' => $job->deleteWhenMissingModels ?? false,
            'data' => [
                'commandName' => $job,
                'command' => $job,
                'batchId' => $job->batchId ?? null,
            ],
            'createdAt' => Date::now()->getTimestamp(),
        ]);

        try {
            $command = $this->jobShouldBeEncrypted($job) && $this->container->bound(EncrypterInterface::class)
                ? $this->container->get(EncrypterInterface::class)->encrypt(serialize(clone $job))
                : serialize(clone $job);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Failed to serialize job of type [%s]: %s', get_class($job), $e->getMessage()),
                0,
                $e
            );
        }

        return array_merge($payload, [
            'data' => array_merge($payload['data'], [
                'commandName' => get_class($job),
                'command' => $command,
            ]),
        ]);
    }

    /**
     * Retourne le nom d'affichage du job (méthode `displayName()` ou FQCN).
     */
    protected function getDisplayName(object $job): string
    {
        return method_exists($job, 'displayName')
            ? $job->displayName()
            : get_class($job);
    }

    /**
     * Retourne le nombre maximal de tentatives défini sur le job objet.
     */
    public function getJobTries(object $job): mixed
    {
        $tries = $job->tries ?? null;

        if (method_exists($job, 'tries')) {
            $tries = $job->tries();
        }

        return $tries;
    }

    /**
     * Retourne le backoff (délai de retry) du job objet, sous forme de liste CSV.
     */
    public function getJobBackoff(object $job): mixed
    {
        $backoff = null;

        if (method_exists($job, 'backoff')) {
            $backoff = $job->backoff();
        } else if (property_exists($job, 'backoff')) {
			$backoff = $job->backoff ?? null;
		}

        if (is_null($backoff)) {
            return null;
        }

        return Collection::wrap($backoff)
            ->map(fn ($backoff) => $backoff instanceof DateTimeInterface ? $this->secondsUntil($backoff) : $backoff)
            ->implode(',');
    }

    /**
     * Retourne l'horodatage d'expiration (`retryUntil`) du job objet.
     */
    public function getJobExpiration(object $job): mixed
    {
        if (! method_exists($job, 'retryUntil') && ! isset($job->retryUntil)) {
            return null;
        }

        $expiration = $job->retryUntil ?? $job->retryUntil();

        return $expiration instanceof DateTimeInterface
            ? $expiration->getTimestamp()
            : $expiration;
    }

    /**
     * Indique si le job doit être chiffré avant d'être persisté.
     */
    protected function jobShouldBeEncrypted(object $job): bool
    {
        return isset($job->shouldBeEncrypted) && $job->shouldBeEncrypted;
    }

    /**
     * Construit un payload classique pour un handler identifié par une chaîne (`Classe@méthode`).
     */
    protected function createStringPayload(string $job, string $queue, mixed $data): array
    {
        return $this->withCreatePayloadHooks($queue, [
            'uuid' => (string) Uuid::v4(),
            'displayName' => is_string($job) ? explode('@', $job)[0] : null,
            'job' => $job,
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'data' => $data,
            'createdAt' => Date::now()->getTimestamp(),
        ]);
    }

    /**
     * Enregistre un callback exécuté à la création des payloads (`null` pour tout réinitialiser).
     */
    public static function createPayloadUsing(?callable $callback = null): void
    {
        if (is_null($callback)) {
            static::$createPayloadCallbacks = [];
        } else {
            static::$createPayloadCallbacks[] = $callback;
        }
    }

    /**
     * Applique les hooks enregistrés au tableau de payload.
     */
    protected function withCreatePayloadHooks(string $queue, array $payload): array
    {
        if (! empty(static::$createPayloadCallbacks)) {
            foreach (static::$createPayloadCallbacks as $callback) {
                $payload = array_merge($payload, $callback($this->getConnectionName(), $queue, $payload));
            }
        }

        return $payload;
    }

    /**
     * Enfile un job via le callback fourni, après avoir émis les événements d'enfilement.
     */
    protected function enqueueUsing(string|object $job, string $payload, ?string $queue, DateTimeInterface|DateInterval|int|null $delay, callable $callback): mixed
    {
		/*
        if ($this->shouldDispatchAfterCommit($job) && $this->container->bound('db.transactions')) {
            if ($job->shouldBeUnique) {
                $this->container->make('db.transactions')->addCallbackForRollback(
                    function () use ($job) {
                        (new UniqueLock($this->container->make(Cache::class)))->release($job);
                    }
                );
            }

            return $this->container->make('db.transactions')->addCallback(
                function () use ($queue, $job, $payload, $delay, $callback) {
                    $this->raiseJobQueueingEvent($queue, $job, $payload, $delay);

                    return tap($callback($payload, $queue, $delay), function ($jobId) use ($queue, $job, $payload, $delay) {
                        $this->raiseJobQueuedEvent($queue, $jobId, $job, $payload, $delay);
                    });
                }
            );
        }
		*/

        $this->raiseJobQueueingEvent($queue, $job, $payload, $delay);

        return tap($callback($payload, $queue, $delay), function ($jobId) use ($queue, $job, $payload, $delay) {
            $this->raiseJobQueuedEvent($queue, $jobId, $job, $payload, $delay);
        });
    }

    /**
     * Indique si le job doit attendre le commit des transactions SQL avant d'être envoyé.
     */
    protected function shouldDispatchAfterCommit(string|object $job): bool
    {
        if (! $job instanceof Closure && is_object($job) && isset($job->afterCommit)) {
            return $job->afterCommit;
        }

        return $this->dispatchAfterCommit ?? false;
    }

    /**
     * Émet l'événement « job en cours d'enfilement ».
     */
    protected function raiseJobQueueingEvent(?string $queue, string|object $job, string $payload, DateTimeInterface|DateInterval|int|null $delay): void
    {
        $this->eventManager()->jobQueueing($this->connectionName, $queue, $job, $payload, $delay);
    }

    /**
     * Émet l'événement « job enfilé ».
     */
    protected function raiseJobQueuedEvent(?string $queue, string|int|null $jobId, string|object $job, string $payload, DateTimeInterface|DateInterval|int|null $delay)
    {
        $this->eventManager()->jobQueued($this->connectionName, $queue, $jobId, $job, $payload, $delay);
    }

    /**
     * Retourne (et instancie si besoin) le gestionnaire d'événements de la file.
     */
    protected function eventManager(): QueueEventManager
    {
        if (! $this->eventManager) {
            $this->eventManager = new QueueEventManager($this->container->get(EventManagerInterface::class));
        }
        
        return $this->eventManager;
    }

    /**
     * Retourne le nom de la connexion.
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Définit le nom de la connexion.
     */
    public function setConnectionName(string $name): self
    {
        $this->connectionName = $name;

        return $this;
    }

    /**
     * Retourne le tableau de configuration de la connexion.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Définit le tableau de configuration de la connexion.
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Retourne le conteneur IoC utilisé par la connexion.
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Définit le conteneur IoC.
     */
    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }
}
