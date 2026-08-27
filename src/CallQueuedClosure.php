<?php

namespace BlitzPHP\Queue;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Queue\Traits\Dispatchable;
use BlitzPHP\Queue\Traits\InteractsWithQueue;
use BlitzPHP\Queue\Traits\SerializesModels;
use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use ReflectionFunction;
use Throwable;

class CallQueuedClosure
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * The serializable Closure instance.
     *
     * @var \Laravel\SerializableClosure\SerializableClosure
     */
    public $closure;

    /**
     * The name assigned to the job.
     */
    public ?string $name = null;

    /**
     * The callbacks that should be executed on failure.
     */
    public array $failureCallbacks = [];

    /**
     * Indicate if the job should be deleted when models are missing.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(SerializableClosure $closure)
    {
        $this->closure = $closure;
    }

    /**
     * Create a new job instance.
     */
    public static function create(Closure $job): self
    {
        return new self(new SerializableClosure($job));
    }

    /**
     * Execute the job.
     */
    public function handle(ContainerInterface $container): void
    {
        $container->call($this->closure->getClosure(), ['job' => $this]);
    }

    /**
     * Add a callback to be executed if the job fails.
     */
    public function onFailure(callable $callback): self
    {
        $this->failureCallbacks[] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;

        return $this;
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $e):void
    {
        foreach ($this->failureCallbacks as $callback) {
            $callback($e);
        }
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        $closure = $this->closure instanceof SerializableClosure
                    ? $this->closure->getClosure()
                    : $this->closure;

        $reflection = new ReflectionFunction($closure);

        $prefix = is_null($this->name) ? '' : "{$this->name} - ";

        return $prefix.'Closure ('.basename($reflection->getFileName()).':'.$reflection->getStartLine().')';
    }

    /**
     * Assign a name to the job.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}
