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
 * @mixin QueueContract
 */
class Manager implements Factory, Monitor
{
    /**
     * The array of resolved queue drivers.
	 *
	 * @var array<string, Queue>
     */
    protected array $drivers = [];

	protected QueueEventManager $queueEventManager;

    protected Cache $cache;

    protected EventManagerInterface $events;

    /**
     * Create a new queue manager instance.
     */
    public function __construct(protected ContainerInterface $container, protected Config $config)
    {
        $this->cache  = $container->get(CacheInterface::class);
        $this->events = $container->get(EventManagerInterface::class);
    }

    /**
     * Register an event listener for the before job event.
     */
    public function before(callable $callback): void
    {
        $this->events->on(QueueEventManager::JOB_PROCESSING, $callback);
    }

    /**
     * Register an event listener for the after job event.
     */
    public function after(callable $callback): void
    {
		$this->events->on(QueueEventManager::JOB_PROCESSED, $callback);
    }

    /**
     * Register an event listener for the exception occurred job event.
     */
    public function exceptionOccurred(callable $callback): void
    {
		$this->events->on(QueueEventManager::JOB_EXCEPTION_OCCURED, $callback);
    }

    /**
     * Register an event listener for the daemon queue loop.
     */
    public function looping(callable $callback): void
    {
		$this->events->on(QueueEventManager::JOB_LOOPING, $callback);
    }

    /**
     * Register an event listener for the failed job event.
     */
    public function failing(callable $callback): void
    {
        $this->events->on(QueueEventManager::JOB_FAILED, $callback);
    }

    /**
     * Register an event listener for the daemon queue starting.
     */
    public function starting(callable $callback): void
    {
		$this->events->on(QueueEventManager::WORKER_STARTING, $callback);
    }

    /**
     * Register an event listener for the daemon queue stopping.
     */
    public function stopping(callable $callback): void
    {
		$this->events->on(QueueEventManager::WORKER_STOPPING, $callback);
    }

	protected function queueEventManager(): QueueEventManager
	{
		if (! $this->queueEventManager) {
			$this->queueEventManager = $this->container->get(QueueEventManager::class);
		}

		return $this->queueEventManager;
	}

    /**
     * Determine if the driver is connected.
     */
    public function connected(UnitEnum|string|null $name = null): bool
    {
        $name = $name instanceof UnitEnum ? $name->name : ($name ?: $this->getDefaultDriver());

        return isset($this->drivers[$name]);
    }

    /**
     * Resolve a queue driver instance.
     */
    public function driver(UnitEnum|string|null $name = null): QueueContract
    {
        $name = $name instanceof UnitEnum ? $name->name : ($name ?: $this->getDefaultDriver());

        // If the driver has not been resolved yet we will resolve it now as all
        // of the drivers are resolved when they are actually needed so we do
        // not make any unnecessary driver to the various queue end-points.
        if (! isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->resolve($name);

            $this->drivers[$name]->setContainer($this->container);
        }

        return $this->drivers[$name];
    }

    /**
     * Resolve a queue connection.
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
     * Pause a queue by its connection and name.
     */
    public function pause(string $connection, string $queue): void
    {
		$this->cache->forever("blitzphp-queue-paused-{$connection}-{$queue}", true);

		$this->queueEventManager()->queuePaused($connection, $queue);
    }

    /**
     * Pause a queue by its connection and name for a given amount of time.
     */
    public function pauseFor(string $connection, string $queue, DateTimeInterface|DateInterval|int $ttl): void
    {
		$convertedTtl = $ttl instanceof DateTimeInterface ? $ttl->getTimestamp() : $ttl;

		$this->cache->set("blitzphp-queue-paused-{$connection}-{$queue}", true, $convertedTtl);

		$this->queueEventManager()->queuePaused($connection, $queue, $ttl);
    }

    /**
     * Resume a paused queue by its connection and name.
     */
    public function resume(string $connection, string $queue): void
    {
		$this->cache->delete("blitzphp-queue-paused-{$connection}-{$queue}");

		$this->queueEventManager()->queueResumed($connection, $queue);
    }

    /**
     * Determine if a queue is paused.
     */
    public function isPaused(string $connection, string $queue): bool
    {
        return (bool) $this->cache->get("blitzphp-queue-paused-{$connection}-{$queue}", false);
    }

    /**
     * Indicate that queue workers should not poll for restart or pause signals.
     *
     * This prevents the workers from hitting the application cache to determine if they need to pause or restart.
     */
    public function withoutInterruptionPolling(): void
    {
        Worker::$restartable = false;
        Worker::$pausable = false;
    }

    /**
     * Get the name of the default queue connection.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->default;
    }
	
	/**
     * Set the name of the default queue connection.
     */
    public function setDefaultDriver(string $name): void
    {
		$this->config->setDefaultDriver($name);
    }

    /**
     * Get the full name for the given connection.
     */
    public function getName(?string $connection = null): string
    {
        return $connection ?: $this->getDefaultDriver();
    }

    /**
     * Get the container instance used by the manager.
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Set the container instance used by the manager.
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
     * Dynamically pass calls to the default connection.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        return $this->driver()->$method(...$parameters);
    }
}
