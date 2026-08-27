<?php

namespace BlitzPHP\Queue\Traits;

use DateTimeInterface;
use BlitzPHP\Contracts\Queue\Job as JobContract;
use BlitzPHP\Queue\Exceptions\ManuallyFailedException;
use BlitzPHP\Queue\Jobs\FakeJob;
use BlitzPHP\Traits\Support\InteractsWithTime;
use DateInterval;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;
use Throwable;

trait InteractsWithQueue
{
    use InteractsWithTime;

    /**
     * The underlying queue job instance.
     */
    public ?JobContract $job = null;

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        return $this->job ? $this->job->attempts() : 1;
    }

    /**
     * Delete the job from the queue.
     */
    public function delete(): void
    {
        if ($this->job) {
            $this->job->delete();
        }
    }

    /**
     * Fail the job from the queue.
     *
     * @throws InvalidArgumentException
     */
    public function fail(Throwable|string|null $exception = null): void
    {
        if (is_string($exception)) {
            $exception = new ManuallyFailedException($exception);
        }

        if ($exception instanceof Throwable || is_null($exception)) {
            if ($this->job) {
                $this->job->fail($exception);
            }
        } else {
            throw new InvalidArgumentException('The fail method requires a string or an instance of Throwable.');
        }
    }

    /**
     * Release the job back into the queue after (n) seconds.
     */
    public function release(DateTimeInterface|DateInterval|int $delay = 0): void
    {
        $delay = $delay instanceof DateTimeInterface
            ? $this->secondsUntil($delay)
            : $delay;

        if ($this->job) {
            $this->job->release($delay);
        }
    }

    /**
     * Indicate that queue interactions like fail, delete, and release should be faked.
     */
    public function withFakeQueueInteractions(): self
    {
        $this->job = new FakeJob;

        return $this;
    }

    /**
     * Assert that the job was deleted from the queue.
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
     * Assert that the job was not deleted from the queue.
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
     * Assert that the job was manually failed.
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
     * Assert that the job was manually failed with a specific exception.
     */
    public function assertFailedWith(Throwable|string  $exception): self
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
     * Assert that the job was not manually failed.
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
     * Assert that the job was released back onto the queue.
     */
    public function assertReleased(DateTimeInterface|DateInterval|int|null $delay = null): self
    {
        $this->ensureQueueInteractionsHaveBeenFaked();

        $delay = $delay instanceof DateTimeInterface
            ? $this->secondsUntil($delay)
            : $delay;

        /* PHPUnit::assertTrue(
            $this->job->isReleased(),
            'Job was expected to be released, but was not.'
        ); */

        if (! is_null($delay)) {
            /* PHPUnit::assertSame(
                $delay,
                $this->job->releaseDelay,
                "Expected job to be released with delay of [{$delay}] seconds, but was released with delay of [{$this->job->releaseDelay}] seconds."
            ); */
        }

        return $this;
    }

    /**
     * Assert that the job was not released back onto the queue.
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
     * Ensure that queue interactions have been faked.
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
     * Set the base queue job instance.
     */
    public function setJob(JobContract $job): self
    {
        $this->job = $job;

        return $this;
    }
}
