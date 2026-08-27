<?php

namespace BlitzPHP\Queue;

use BlitzPHP\Cache\Cache;
use BlitzPHP\Contracts\Cache\CacheInterface;
use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Contracts\Queue\Factory;
use BlitzPHP\Contracts\Queue\Monitor;
use BlitzPHP\Contracts\Queue\Queue as QueueContract;
use BlitzPHP\Queue\DTO\Config;
use BlitzPHP\Queue\Events\QueueEventManager;
use Closure;
use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use UnitEnum;

/**
 * Gestionnaire des files d'attente.
 *
 * Résout les pilotes, expose les connexions et permet d'écouter le cycle de vie
 * des jobs et des workers. Les appels magiques sont délégués à la connexion par défaut.
 *
 * @mixin QueueContract
 */
class Manager implements Factory, Monitor
{
    /**
     * Instances de pilotes déjà résolues, indexées par nom de connexion.
     *
     * @var array<string, Queue>
     */
    protected array $drivers = [];

    /**
     * Gestionnaire d'événements de la file.
     */
	protected QueueEventManager $queueEventManager;

    /**
     * Cache applicatif (pause / redémarrage des workers).
     */
    protected Cache $cache;

    /**
     * Gestionnaire d'événements de l'application.
     */
    protected EventManagerInterface $events;

    /**
     * Crée une instance du gestionnaire de files.
     */
    public function __construct(protected ContainerInterface $container, protected Config $config)
    {
        $this->cache  = $container->get(CacheInterface::class);
        $this->events = $container->get(EventManagerInterface::class);
    }

    /**
     * Enregistre un écouteur exécuté avant le traitement d'un job.
     */
    public function before(callable $callback): void
    {
        $this->events->on(QueueEventManager::JOB_PROCESSING, $callback);
    }

    /**
     * Enregistre un écouteur exécuté après le traitement réussi d'un job.
     */
    public function after(callable $callback): void
    {
		$this->events->on(QueueEventManager::JOB_PROCESSED, $callback);
    }

    /**
     * Enregistre un écouteur lorsqu'une exception survient pendant un job.
     */
    public function exceptionOccurred(callable $callback): void
    {
		$this->events->on(QueueEventManager::JOB_EXCEPTION_OCCURED, $callback);
    }

    /**
     * Enregistre un écouteur à chaque itération de la boucle du daemon.
     */
    public function looping(callable $callback): void
    {
		$this->events->on(QueueEventManager::JOB_LOOPING, $callback);
    }

    /**
     * Enregistre un écouteur lorsqu'un job échoue définitivement.
     */
    public function failing(callable $callback): void
    {
        $this->events->on(QueueEventManager::JOB_FAILED, $callback);
    }

    /**
     * Enregistre un écouteur au démarrage du worker daemon.
     */
    public function starting(callable $callback): void
    {
		$this->events->on(QueueEventManager::WORKER_STARTING, $callback);
    }

    /**
     * Enregistre un écouteur à l'arrêt du worker daemon.
     */
    public function stopping(callable $callback): void
    {
		$this->events->on(QueueEventManager::WORKER_STOPPING, $callback);
    }

    /**
     * Retourne (et instancie si besoin) le gestionnaire d'événements de file.
     */
	protected function queueEventManager(): QueueEventManager
	{
		if (! $this->queueEventManager) {
			$this->queueEventManager = $this->container->get(QueueEventManager::class);
		}

		return $this->queueEventManager;
	}

    /**
     * Indique si le pilote (connexion) donné est déjà résolu.
     */
    public function connected(UnitEnum|string|null $name = null): bool
    {
        $name = $name instanceof UnitEnum ? $name->name : ($name ?: $this->getDefaultDriver());

        return isset($this->drivers[$name]);
    }

    /**
     * Résout une instance de connexion de file d'attente.
     *
     * Les pilotes sont instanciés à la demande pour éviter les connexions inutiles.
     */
    public function driver(UnitEnum|string|null $name = null): QueueContract
    {
        $name = $name instanceof UnitEnum ? $name->name : ($name ?: $this->getDefaultDriver());

        // Si le pilote n'a pas encore été résolu, on l'instancie maintenant :
        // les connexions ne sont ouvertes que lorsqu'elles sont réellement utilisées.
        if (! isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->resolve($name);

            $this->drivers[$name]->setContainer($this->container);
        }

        return $this->drivers[$name];
    }

    /**
     * Instancie une connexion à partir de sa configuration.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): Queue
    {
        $config = $this->config->connection($name);
        $driver = $this->config->driver($config['driver']);

        $queue = $driver::connect($this->container, $config)->setConnectionName($name);

        if (method_exists($queue, 'setConfig')) {
            $queue->setConfig($config);
        }

        return $queue;
    }

    /**
     * Met une file en pause (les workers cessent d'y prélever des jobs).
     */
    public function pause(string $connection, string $queue): void
    {
		$this->cache->forever("blitzphp-queue-paused-{$connection}-{$queue}", true);

		$this->queueEventManager()->queuePaused($connection, $queue);
    }

    /**
     * Met une file en pause pendant une durée donnée.
     */
    public function pauseFor(string $connection, string $queue, DateTimeInterface|DateInterval|int $ttl): void
    {
		$convertedTtl = $ttl instanceof DateTimeInterface ? $ttl->getTimestamp() : $ttl;

		$this->cache->set("blitzphp-queue-paused-{$connection}-{$queue}", true, $convertedTtl);

		$this->queueEventManager()->queuePaused($connection, $queue, $ttl);
    }

    /**
     * Reprend une file précédemment mise en pause.
     */
    public function resume(string $connection, string $queue): void
    {
		$this->cache->delete("blitzphp-queue-paused-{$connection}-{$queue}");

		$this->queueEventManager()->queueResumed($connection, $queue);
    }

    /**
     * Indique si une file est actuellement en pause.
     */
    public function isPaused(string $connection, string $queue): bool
    {
        return (bool) $this->cache->get("blitzphp-queue-paused-{$connection}-{$queue}", false);
    }

    /**
     * Désactive le sondage cache des signaux de pause et de redémarrage.
     *
     * Évite que les workers interrogent le cache applicatif pour savoir s'ils
     * doivent se mettre en pause ou redémarrer.
     */
    public function withoutInterruptionPolling(): void
    {
        Worker::$restartable = false;
        Worker::$pausable = false;
    }

    /**
     * Retourne le nom de la connexion par défaut.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->default;
    }
	
	/**
     * Définit le nom de la connexion par défaut.
     */
    public function setDefaultDriver(string $name): void
    {
		$this->config->setDefaultDriver($name);
    }

    /**
     * Retourne le nom effectif d'une connexion (ou la connexion par défaut).
     */
    public function getName(?string $connection = null): string
    {
        return $connection ?: $this->getDefaultDriver();
    }

    /**
     * Retourne le conteneur utilisé par le gestionnaire.
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Définit le conteneur et le propage aux pilotes déjà résolus.
     */
    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;

        foreach ($this->drivers as $driver) {
            $driver->setContainer($container);
        }

        return $this;
    }

    /**
     * Délègue dynamiquement les appels à la connexion par défaut.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        return $this->driver()->$method(...$parameters);
    }
}
