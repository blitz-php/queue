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
use BlitzPHP\Utilities\DateTime\Date;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\String\Text;
use Closure;
use DateInterval;
use DateTimeInterface;
use RuntimeException;
use Throwable;

abstract class Queue implements QueueContract
{
    use InteractsWithTime;

    /**
     * The IoC container instance.
     */
    protected ContainerInterface $container;

    /**
     * The connection name for the queue.
     */
    protected string $connectionName;

    /**
     * The original configuration for the queue.
     */
    protected array $config;

    /**
     * Indicates that jobs should be dispatched after all database transactions have committed.
     */
    protected bool $dispatchAfterCommit;

    /**
     * The create payload callbacks.
     *
     * @var callable[]
     */
    protected static array $createPayloadCallbacks = [];

    /**
     * Push a new job onto the queue.
     */
    public function pushOn(string $queue, string|Job $job, mixed $data = ''): mixed
    {
        return $this->push($job, $data, $queue);
    }

    /**
     * Push a new job onto a specific queue after (n) seconds.
     */
    public function laterOn(string $queue, DateTimeInterface|DateInterval|int $delay, string|Job $job, mixed $data = ''): mixed
    {
        return $this->later($delay, $job, $data, $queue);
    }

    /**
     * Push an array of jobs onto the queue.
     *
     * @param  array<string|Job> $jobs
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
     * Create a payload string from the given job and data.
     *
     *
     * @throws InvalidPayloadException
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
     * Create a payload array from the given job and data.
     */
    protected function createPayloadArray(string|object $job, string $queue, mixed $data = ''): array
    {
        return is_object($job)
            ? $this->createObjectPayload($job, $queue)
            : $this->createStringPayload($job, $queue, $data);
    }

    /**
     * Create a payload for an object-based queue handler.
     *
     * @throws RuntimeException
     */
    protected function createObjectPayload(object $job, string $queue): array
    {
        $payload = $this->withCreatePayloadHooks($queue, [
            'uuid' => (string) Text::uuid(),
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
     * Get the display name for the given job.
     */
    protected function getDisplayName(object $job): string
    {
        return method_exists($job, 'displayName')
            ? $job->displayName()
            : get_class($job);
    }

    /**
     * Get the maximum number of attempts for an object-based queue handler.
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
     * Get the backoff for an object-based queue handler.
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
     * Get the expiration timestamp for an object-based queue handler.
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
     * Determine if the job should be encrypted.
     */
    protected function jobShouldBeEncrypted(object $job): bool
    {
        return isset($job->shouldBeEncrypted) && $job->shouldBeEncrypted;
    }

    /**
     * Create a typical, string based queue payload array.
     */
    protected function createStringPayload(string $job, string $queue, mixed $data): array
    {
        return $this->withCreatePayloadHooks($queue, [
            'uuid' => (string) Text::uuid(),
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
     * Register a callback to be executed when creating job payloads.
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
     * Create the given payload using any registered payload hooks.
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
     * Enqueue a job using the given callback.
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
     * Determine if the job should be dispatched after all database transactions have committed.
     */
    protected function shouldDispatchAfterCommit(string|object $job): bool
    {
        if (! $job instanceof Closure && is_object($job) && isset($job->afterCommit)) {
            return $job->afterCommit;
        }

        return $this->dispatchAfterCommit ?? false;
    }

    /**
     * Raise the job queueing event.
     */
    protected function raiseJobQueueingEvent(string $queue, string|object $job, string $payload, DateTimeInterface|DateInterval|int|null $delay): void
    {
        if ($this->container->bound(EventManagerInterface::class)) {
            $delay = ! is_null($delay) ? $this->secondsUntil($delay) : $delay;

			$this->container->get(QueueEventManager::class)->jobQueueing($this->connectionName, $queue, $job, $payload, $delay);
        }
    }

    /**
     * Raise the job queued event.
     */
    protected function raiseJobQueuedEvent(?string $queue, string|int|null $jobId, string|object $job, string $payload, DateTimeInterface|DateInterval|int|null $delay)
    {
        if ($this->container->bound(EventManagerInterface::class)) {
            $delay = ! is_null($delay) ? $this->secondsUntil($delay) : $delay;

			$this->container->get(QueueEventManager::class)->jobQueued($this->connectionName, $queue, $jobId, $job, $payload, $delay);
        }
    }

    /**
     * Get the connection name for the queue.
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Set the connection name for the queue.
     */
    public function setConnectionName(string $name): self
    {
        $this->connectionName = $name;

        return $this;
    }

    /**
     * Get the queue configuration array.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set the queue configuration array.
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Get the container instance being used by the connection.
     */
    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Set the IoC container instance.
     */
    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }
}
