<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Traits;

use BlitzPHP\Contracts\Queue\Job as JobContract;
use BlitzPHP\Queue\Exceptions\ManuallyFailedException;
use BlitzPHP\Queue\Jobs\FakeJob;
use BlitzPHP\Traits\Support\InteractsWithTime;
use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;
use Throwable;

/**
 * Interactions d'un job métier avec la file (delete, fail, release) et assertions de test.
 */
trait InteractsWithQueue
{
    use InteractsWithTime;

    /**
     * Instance de job de file sous-jacente.
     */
    public ?JobContract $job = null;

    /**
     * Retourne le nombre de tentatives déjà effectuées.
     */
    public function attempts(): int
    {
        return $this->job ? $this->job->attempts() : 1;
    }

    /**
     * Supprime le job de la file.
     */
    public function delete(): void
    {
        if ($this->job) {
            $this->job->delete();
        }
    }

    /**
     * Marque le job en échec depuis la file.
     *
     * @throws InvalidArgumentException
     */
    public function fail(string|Throwable|null $exception = null): void
    {
        if (is_string($exception)) {
            $exception = new ManuallyFailedException($exception);
        }

        if ($exception instanceof Throwable || null === $exception) {
            if ($this->job) {
                $this->job->fail($exception);
            }
        } else {
            throw new InvalidArgumentException('The fail method requires a string or an instance of Throwable.');
        }
    }

    /**
     * Relâche le job dans la file après n secondes.
     */
    public function release(DateInterval|DateTimeInterface|int $delay = 0): void
    {
        $delay = $delay instanceof DateTimeInterface
            ? $this->secondsUntil($delay)
            : $delay;

        if ($this->job) {
            $this->job->release($delay);
        }
    }

    /**
     * Active le mode simulé pour fail, delete et release.
     */
    public function withFakeQueueInteractions(): self
    {
        $this->job = new FakeJob();

        return $this;
    }

    /**
     * Vérifie que le job a été supprimé de la file.
     */
    public function assertDeleted(): self
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        /* PHPUnit::assertTrue(
            $this->job->isDeleted(),
            'Job was expected to be deleted, but was not.'
        ); */

        return $this;
    }

    /**
     * Vérifie que le job n'a pas été supprimé de la file.
     */
    public function assertNotDeleted(): self
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        /* PHPUnit::assertTrue(
            ! $this->job->isDeleted(),
            'Job was unexpectedly deleted.'
        ); */

        return $this;
    }

    /**
     * Vérifie que le job a été marqué en échec manuellement.
     */
    public function assertFailed(): self
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        /* PHPUnit::assertTrue(
            $this->job->hasFailed(),
            'Job was expected to be manually failed, but was not.'
        ); */

        return $this;
    }

    /**
     * Vérifie que le job a échoué manuellement avec une exception donnée.
     */
    public function assertFailedWith(string|Throwable $exception): self
    {
        $this->assertFailed();

        if (is_string($exception) && class_exists($exception)) {
            /* PHPUnit::assertInstanceOf(
                $exception,
                $this->job->failedWith,
                'Expected job to be manually failed with ['.$exception.'] but job failed with ['.get_class($this->job->failedWith).'].'
            ); */

            return $this;
        }

        if (is_string($exception)) {
            $exception = new ManuallyFailedException($exception);
        }

        if ($exception instanceof Throwable) {
            /* PHPUnit::assertInstanceOf(
                get_class($exception),
                $this->job->failedWith,
                'Expected job to be manually failed with ['.get_class($exception).'] but job failed with ['.get_class($this->job->failedWith).'].'
            );

            PHPUnit::assertEquals(
                $exception->getCode(),
                $this->job->failedWith->getCode(),
                'Expected exception code ['.$exception->getCode().'] but job failed with exception code ['.$this->job->failedWith->getCode().'].'
            );

            PHPUnit::assertEquals(
                $exception->getMessage(),
                $this->job->failedWith->getMessage(),
                'Expected exception message ['.$exception->getMessage().'] but job failed with exception message ['.$this->job->failedWith->getMessage().'].');
            */
        }

        return $this;
    }

    /**
     * Vérifie que le job n'a pas été marqué en échec manuellement.
     */
    public function assertNotFailed(): self
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        /* PHPUnit::assertTrue(
            ! $this->job->hasFailed(),
            'Job was unexpectedly failed manually.'
        ); */

        return $this;
    }

    /**
     * Vérifie que le job a été relâché dans la file.
     */
    public function assertReleased(DateInterval|DateTimeInterface|int|null $delay = null): self
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        $delay = $delay instanceof DateTimeInterface
            ? $this->secondsUntil($delay)
            : $delay;

        /* PHPUnit::assertTrue(
            $this->job->isReleased(),
            'Job was expected to be released, but was not.'
        ); */

        if (null !== $delay) {
            /* PHPUnit::assertSame(
                $delay,
                $this->job->releaseDelay,
                "Expected job to be released with delay of [{$delay}] seconds, but was released with delay of [{$this->job->releaseDelay}] seconds."
            ); */
        }

        return $this;
    }

    /**
     * Vérifie que le job n'a pas été relâché dans la file.
     */
    public function assertNotReleased(): self
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        /* PHPUnit::assertTrue(
            ! $this->job->isReleased(),
            'Job was unexpectedly released.'
        ); */

        return $this;
    }

    /**
     * S'assure que les interactions de file ont été simulées.
     *
     * @throws RuntimeException
     */
    private function ensureQueueInteractionsHaveBeenFaked(): void
    {
        if (! $this->job instanceof FakeJob) {
            throw new RuntimeException('Queue interactions have not been faked.');
        }
    }

    /**
     * Définit l'instance de job de file sous-jacente.
     */
    public function setJob(JobContract $job): self
    {
        $this->job = $job;

        return $this;
    }
}
