<?php

namespace BlitzPHP\Queue;

use BlitzPHP\Cache\Cache;
use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Contracts\Queue\Factory;
use BlitzPHP\Contracts\Queue\Monitor;
use BlitzPHP\Contracts\Queue\Queue as QueueContract;
use BlitzPHP\Queue\Drivers\ConnectorInterface;
use BlitzPHP\Queue\Events\QueueEventManager;
use BlitzPHP\Utilities\Helpers;
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
     * The array of resolved queue connections.
	 *
	 * @var array<string, Queue>
     */
    protected array $connections = [];

    /**
     * The array of resolved queue connectors.
     */
    protected array $connectors = [];

	protected QueueEventManager $queueEventManager;

    /**
     * Create a new queue manager instance.
     */
    public function __construct(protected ContainerInterface $container)
    {
    }

    /**
     * Register an event listener for the before job event.
     */
    public function before(callable $callback): void
    {
		$this->container->get(EventManagerInterface::class)->on(
			QueueEventManager::JOB_PROCESSING,
			$callback
		);
    }

    /**
     * Register an event listener for the after job event.
     */
    public function after(callable $callback): void
    {
		$this->container->get(EventManagerInterface::class)->on(
			QueueEventManager::JOB_PROCESSED,
			$callback
		);
    }

    /**
     * Register an event listener for the exception occurred job event.
     */
    public function exceptionOccurred(callable $callback): void
    {
		$this->container->get(EventManagerInterface::class)->on(
			QueueEventManager::JOB_EXCEPTION_OCCURED,
			$callback
		);
    }

    /**
     * Register an event listener for the daemon queue loop.
     */
    public function looping(callable $callback): void
    {
		$this->container->get(EventManagerInterface::class)->on(
			QueueEventManager::JOB_LOOPING,
			$callback
		);
    }

    /**
     * Register an event listener for the failed job event.
     */
    public function failing(callable $callback): void
    {
        $this->container->get(EventManagerInterface::class)->on(
			QueueEventManager::JOB_FAILED,
			$callback
		);
    }

    /**
     * Register an event listener for the daemon queue starting.
     */
    public function starting(callable $callback): void
    {
		$this->container->get(EventManagerInterface::class)->on(
			QueueEventManager::WORKER_STARTING,
			$callback
		);
    }

    /**
     * Register an event listener for the daemon queue stopping.
     */
    public function stopping(callable $callback): void
    {
		$this->container->get(EventManagerInterface::class)->on(
			QueueEventManager::WORKER_STOPPING,
			$callback
		);
    }

	protected function queueEventManager(): QueueEventManager
	{
		if (! $this->queueEventManager) {
			$this->queueEventManager = $this->container->make(QueueEventManager::class);
		}

		return $this->queueEventManager;
	}

    /**
     * Determine if the driver is connected.
     */
    public function connected(UnitEnum|string|null $name = null): bool
    {
        return isset($this->connections[Helpers::enumValue($name) ?: $this->getDefaultDriver()]);
    }

    /**
     * Resolve a queue connection instance.
     */
    public function connection(UnitEnum|string|null $name = null): QueueContract
    {
        $name = Helpers::enumValue($name) ?: $this->getDefaultDriver();

        // If the connection has not been resolved yet we will resolve it now as all
        // of the connections are resolved when they are actually needed so we do
        // not make any unnecessary connection to the various queue end-points.
        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->resolve($name);

            $this->connections[$name]->setContainer($this->container);
        }

        return $this->connections[$name];
    }

    /**
     * Resolve a queue connection.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): Queue
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("The [{$name}] queue connection has not been configured.");
        }

        $queue = $this->getConnector($config['driver'])
            ->connect($this->container, $config)
            ->setConnectionName($name);

        if (method_exists($queue, 'setConfig')) {
            $queue->setConfig($config);
        }

        return $queue;
    }

    /**
     * Get the connector for a given driver.
     *
     * @throws InvalidArgumentException
     */
    protected function getConnector(string $driver): ConnectorInterface
    {
        if (! isset($this->connectors[$driver])) {
            throw new InvalidArgumentException("No connector for [$driver].");
        }

        return call_user_func($this->connectors[$driver]);
    }

    /**
     * Pause a queue by its connection and name.
     */
    public function pause(string $connection, string $queue): void
    {
		$this->container->get(Cache::class)
			->forever("blitzphp:queue:paused:{$connection}:{$queue}", true);

		$this->queueEventManager()->queuePaused($connection, $queue);
    }

    /**
     * Pause a queue by its connection and name for a given amount of time.
     */
    public function pauseFor(string $connection, string $queue, DateTimeInterface|DateInterval|int $ttl): void
    {
		$convertedTtl = $ttl instanceof DateTimeInterface ? $ttl->getTimestamp() : $ttl;

		$this->container->get(Cache::class)
			->set("blitzphp:queue:paused:{$connection}:{$queue}", true, $convertedTtl);

		$this->queueEventManager()->queuePaused($connection, $queue, $ttl);
    }

    /**
     * Resume a paused queue by its connection and name.
     */
    public function resume(string $connection, string $queue): void
    {
		$this->container->get(Cache::class)
			->delete("blitzphp:queue:paused:{$connection}:{$queue}");

		$this->queueEventManager()->queueResumed($connection, $queue);
    }

    /**
     * Determine if a queue is paused.
     */
    public function isPaused(string $connection, string $queue): bool
    {
        return (bool) $this->container->get(Cache::class)
			->get("blitzphp:queue:paused:{$connection}:{$queue}", false);
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
     * Add a queue connection resolver.
     */
    public function extend(string $driver, Closure $resolver): void
    {
        $this->addConnector($driver, $resolver);
    }

    /**
     * Add a queue connection resolver.
     */
    public function addConnector(string $driver, Closure $resolver): void
    {
        $this->connectors[$driver] = $resolver;
    }

    /**
     * Get the queue connection configuration.
     */
    protected function getConfig(string $name): ?array
    {
        if (! is_null($name) && $name !== 'null') {
			return config("queue.connections.{$name}");
        }

        return ['driver' => 'null'];
    }

    /**
     * Get the name of the default queue connection.
     */
    public function getDefaultDriver(): string
    {
		return config('queue.default');
    }

    /**
     * Set the name of the default queue connection.
     */
    public function setDefaultDriver(string $name): void
    {
		config()->set('queue.default', $name);
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
    public function setContainer(ContainerInterface $container)
    {
        $this->container = $container;

        foreach ($this->connections as $connection) {
            $connection->setContainer($container);
        }

        return $this;
    }

    /**
     * Dynamically pass calls to the default connection.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        return $this->connection()->$method(...$parameters);
    }
}
