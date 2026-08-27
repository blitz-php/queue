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

/**
 * Job enveloppe d'une Closure sérialisable, exécutable par le worker.
 */
class CallQueuedClosure
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * Instance de Closure sérialisable.
     *
     * @var \Laravel\SerializableClosure\SerializableClosure
     */
    public $closure;

    /**
     * Nom assigné au job.
     */
    public ?string $name = null;

    /**
     * Callbacks à exécuter en cas d'échec.
     */
    public array $failureCallbacks = [];

    /**
     * Indique si le job doit être supprimé lorsque des modèles sont introuvables.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Crée une nouvelle instance de job.
     */
    public function __construct(SerializableClosure $closure)
    {
        $this->closure = $closure;
    }

    /**
     * Crée une nouvelle instance de job.
     */
    public static function create(Closure $job): self
    {
        return new self(new SerializableClosure($job));
    }

    /**
     * Exécute le job.
     */
    public function handle(ContainerInterface $container): void
    {
        $container->call($this->closure->getClosure(), ['job' => $this]);
    }

    /**
     * Ajoute un callback exécuté si le job échoue.
     */
    public function onFailure(callable $callback): self
    {
        $this->failureCallbacks[] = $callback instanceof Closure
            ? new SerializableClosure($callback)
            : $callback;

        return $this;
    }

    /**
     * Traite l'échec du job.
     */
    public function failed(Throwable $e):void
    {
        foreach ($this->failureCallbacks as $callback) {
            $callback($e);
        }
    }

    /**
     * Retourne le nom d'affichage du job enfilé.
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
     * Assigne un nom au job.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }
}
