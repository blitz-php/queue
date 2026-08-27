<?php

namespace BlitzPHP\Queue\Failed;

use BlitzPHP\Utilities\Date;
use BlitzPHP\Utilities\Iterable\Collection;
use Closure;
use DateTimeInterface;
use Throwable;

/**
 * Stocke les jobs échoués dans un fichier JSON, avec un plafond d'entrées.
 */
class FileFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    /**
     * Crée un fournisseur de jobs échoués sur fichier.
     *
     * @param  string  $path Chemin du fichier de stockage des jobs échoués.
     * @param  int  $limit Nombre maximal de jobs échoués à conserver.
     * @param  Closure|null  $lockProviderResolver Résolveur du fournisseur de verrous.
     */
    public function __construct(protected string $path, protected int $limit = 100, protected ?Closure $lockProviderResolver = null)
    {
    }

    /**
     * Enregistre un job échoué dans le stockage.
     */
    public function log(string $connection, string $queue, string $payload, Throwable $exception): ?int
    {
        return $this->lock(function () use ($connection, $queue, $payload, $exception) {
            $id = json_decode($payload, true)['uuid'];

            $jobs = $this->read();

            $failedAt = Date::now();

            array_unshift($jobs, [
                'id'                  => $id,
                'connection'          => $connection,
                'queue'               => $queue,
                'payload'             => $payload,
                'exception'           => (string) mb_convert_encoding($exception, 'UTF-8'),
                'failed_at'           => $failedAt->format('Y-m-d H:i:s'),
                'failed_at_timestamp' => $failedAt->getTimestamp(),
            ]);

            $this->write(array_slice($jobs, 0, $this->limit));

            return $id;
        });
    }

    /**
     * Retourne les identifiants de tous les jobs échoués.
     */
    public function ids(?string $queue = null): array
    {
        return (new Collection($this->all()))
            ->when(! is_null($queue), fn ($collect) => $collect->where('queue', $queue))
            ->pluck('id')
            ->all();
    }

    /**
     * Retourne la liste de tous les jobs échoués.
     */
    public function all(): array
    {
        return $this->read();
    }

    /**
     * Retourne un job échoué.
     */
    public function find(int|string $id): ?object
    {
        return (new Collection($this->read()))
            ->first(fn ($job) => $job->id === $id);
    }

    /**
     * Supprime un job échoué du stockage.
     */
    public function forget(string|int $id): bool
    {
        return $this->lock(function () use ($id) {
            $this->write($pruned = (new Collection($jobs = $this->read()))
                ->reject(fn ($job) => $job->id === $id)
                ->values()
                ->all());

            return count($jobs) !== count($pruned);
        });
    }

    /**
     * Vide le stockage des jobs échoués.
     */
    public function flush(?int $hours = null): void
    {
        $this->prune(Date::now()->subHours($hours ?: 0));
    }

    /**
     * Purge les entrées antérieures à la date donnée.
     */
    public function prune(DateTimeInterface $before): int
    {
        return $this->lock(function () use ($before) {
            $jobs = $this->read();

            $this->write($prunedJobs = (new Collection($jobs))
                ->reject(fn ($job) => $job->failed_at_timestamp <= $before->getTimestamp())
                ->values()
                ->all()
            );

            return count($jobs) - count($prunedJobs);
        });
    }

    /**
     * Exécute le callback en détenant un verrou.
     */
    protected function lock(Closure $callback): mixed
    {
        if (! $this->lockProviderResolver) {
            return $callback();
        }

        return ($this->lockProviderResolver)()
            ->lock('blitzphp-failed-jobs', 5)
            ->block(10, function () use ($callback) {
                return $callback();
            });
    }

    /**
     * Lit le fichier des jobs échoués.
     */
    protected function read(): array
    {
        if (! file_exists($this->path)) {
            return [];
        }

        $content = file_get_contents($this->path);

        if (empty(trim($content))) {
            return [];
        }

        $content = json_decode($content);

        return is_array($content) ? $content : [];
    }

    /**
     * Écrit le tableau de jobs dans le fichier des échecs.
     */
    protected function write(array $jobs): void
    {
        file_put_contents(
            $this->path,
            json_encode($jobs, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Compte les jobs échoués.
     */
    public function count(?string $connection = null, ?string $queue = null): int
    {
        if (($connection ?? $queue) === null) {
            return count($this->read());
        }

        return (new Collection($this->read()))
            ->filter(fn ($job) => $job->connection === ($connection ?? $job->connection) && $job->queue === ($queue ?? $job->queue))
            ->count();
    }
}
