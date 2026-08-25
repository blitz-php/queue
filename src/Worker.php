<?php

namespace BlitzPHP\Queue;

use BlitzPHP\Cache\Cache;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Contracts\Queue\Queue;
use BlitzPHP\Queue\Enums\WorkerStopReason;
use BlitzPHP\Queue\Events\QueueEventManager;
use BlitzPHP\Queue\Exceptions\MaxAttemptsExceededException;
use BlitzPHP\Queue\Exceptions\TimeoutExceededException;
use BlitzPHP\Utilities\DateTime\Date;
use Illuminate\Contracts\Debug\ExceptionHandler;
// use BlitzPHP\Database\DetectsLostConnections; // available only in blitz-php/database 1.1
use Throwable;

class Worker
{
    // use DetectsLostConnections;

    const EXIT_SUCCESS = EXIT_SUCCESS;
    const EXIT_ERROR = EXIT_ERROR;
    const EXIT_MEMORY_LIMIT = 12;

    /**
     * The name of the worker.
     */
    protected ?string $name;


    /**
     * The cache repository implementation.
     */
    protected Cache $cache;

    /**
     * The exception handler instance.
     *
     * @var \Illuminate\Contracts\Debug\ExceptionHandler
     */
    protected $exceptions;

    /**
     * The callback used to determine if the application is in maintenance mode.
     *
     * @var callable
     */
    protected $isDownForMaintenance;

    /**
     * The callback used to reset the application's scope.
     *
     * @var callable
     */
    protected $resetScope;

    /**
     * Indicates if the worker should exit.
     */
    public bool $shouldQuit = false;

    /**
     * Indicates if the worker lost its connection.
     */
    public bool $lostConnection = false;

    /**
     * Indicates if the worker is paused.
     */
    public bool $paused = false;

    /**
     * The callbacks used to pop jobs from queues.
     *
     * @var callable[]
     */
    protected static array $popCallbacks = [];

    /**
     * The custom exit code to be used when memory is exceeded.
     */
    public static ?int $memoryExceededExitCode = null;

    /**
     * Indicates if the worker should report job exceptions.
     */
    public static bool $reportJobExceptions = true;

    /**
     * Indicates if the worker should check for the restart signal in the cache.
     */
    public static bool $restartable = true;

    /**
     * Indicates if the worker should check for the paused signal in the cache.
     */
    public static bool $pausable = true;

    /**
     * Create a new queue worker.
     *
     * @param  Manager $manager The queue manager instance.
     * @param  QueueEventManager  $events The queue event manager instance.
     * @param  \Illuminate\Contracts\Debug\ExceptionHandler  $exceptions
     */
    public function __construct(
        protected Manager $manager,
        protected QueueEventManager $events,
        // ExceptionHandler $exceptions,
        callable $isDownForMaintenance,
        ?callable $resetScope = null,
    ) {
        // $this->exceptions = $exceptions;
        $this->isDownForMaintenance = $isDownForMaintenance;
        $this->resetScope = $resetScope;
    }

    /**
     * Listen to the given queue in a loop.
     */
    public function daemon(string $connectionName, string $queue, WorkerOptions $options): int
    {
        if ($supportsAsyncSignals = $this->supportsAsyncSignals()) {
            $this->listenForSignals();
        }

        $lastRestart = $this->getTimestampOfLastQueueRestart();

        [$startTime, $jobsProcessed] = [hrtime(true) / 1e9, 0];

        $this->raiseWorkerStartingEvent($connectionName, $queue, $options);

        while (true) {
            // Before reserving any jobs, we will make sure this queue is not paused and
            // if it is we will just pause this worker for a given amount of time and
            // make sure we do not need to kill this worker process off completely.
            if (! $this->daemonShouldRun($options, $connectionName, $queue)) {
                [$status, $reason] = $this->pauseWorker($options, $lastRestart);

                if (! is_null($status)) {
                    return $this->stop($status, $options, $reason);
                }

                continue;
            }

            if (isset($this->resetScope)) {
                ($this->resetScope)();
            }

            // First, we will attempt to get the next job off of the queue. We will also
            // register the timeout handler and reset the alarm for this job so it is
            // not stuck in a frozen state forever. Then, we can fire off this job.
            $job = $this->getNextJob(
                $this->manager->connection($connectionName), $queue
            );

            if ($supportsAsyncSignals) {
                $this->registerTimeoutHandler($job, $options);
            }

            // If the daemon should run (not in maintenance mode, etc.), then we can run
            // fire off this job for processing. Otherwise, we will need to sleep the
            // worker so no more jobs are processed until they should be processed.
            if ($job) {
                $jobsProcessed++;

                $this->runJob($job, $connectionName, $options);

                if ($options->rest > 0) {
                    $this->sleep($options->rest);
                }
            } else {
                $this->sleep($options->sleep);
            }

            if ($supportsAsyncSignals) {
                $this->resetTimeoutHandler();
            }

            // Finally, we will check to see if we have exceeded our memory limits or if
            // the queue should restart based on other indications. If so, we'll stop
            // this worker and let whatever is "monitoring" it restart the process.
            [$status, $reason] = $this->stopIfNecessary(
                $options, $lastRestart, $startTime, $jobsProcessed, $job
            );

            if (! is_null($status)) {
                return $this->stop($status, $options, $reason);
            }
        }
    }

    /**
     * Register the worker timeout handler.
     */
    protected function registerTimeoutHandler(Job $job, WorkerOptions $options): void
    {
        // We will register a signal handler for the alarm signal so that we can kill this
        // process if it is running too long because it has frozen. This uses the async
        // signals supported in recent versions of PHP to accomplish it conveniently.
        pcntl_signal(SIGALRM, function () use ($job, $options) {
            if ($job) {
                $this->markJobAsFailedIfWillExceedMaxAttempts(
                    $job->getConnectionName(), $job, (int) $options->maxTries, $e = $this->timeoutExceededException($job)
                );

                $this->markJobAsFailedIfWillExceedMaxExceptions(
                    $job->getConnectionName(), $job, $e
                );

                $this->markJobAsFailedIfItShouldFailOnTimeout(
                    $job->getConnectionName(), $job, $e
                );

				$this->events->jobTimeout($job->getConnectionName(), $job->getQueue(), $job);
            }

            $this->kill(static::EXIT_ERROR, $options, WorkerStopReason::TimedOut);
        }, true);

        pcntl_alarm(
            max($this->timeoutForJob($job, $options), 0)
        );
    }

    /**
     * Reset the worker timeout handler.
     */
    protected function resetTimeoutHandler(): void
    {
        pcntl_alarm(0);
    }

    /**
     * Get the appropriate timeout for the given job.
     */
    protected function timeoutForJob(Job $job, WorkerOptions $options): int
    {
        return $job && ! is_null($job->timeout()) ? $job->timeout() : $options->timeout;
    }

    /**
     * Determine if the daemon should process on this iteration.
     */
    protected function daemonShouldRun(WorkerOptions $options, string $connectionName, string $queue): bool
    {
        return ! (($this->isDownForMaintenance)() && ! $options->force) ||
            $this->paused;
    }

    /**
     * Pause the worker for the current loop.
     */
    protected function pauseWorker(WorkerOptions $options, int $lastRestart): ?array
    {
        $this->sleep($options->sleep > 0 ? $options->sleep : 1);

        return $this->stopIfNecessary($options, $lastRestart);
    }

    /**
     * Determine the exit code to stop the process if necessary.
     */
    protected function stopIfNecessary(WorkerOptions $options, int $lastRestart, int $startTime = 0, int $jobsProcessed = 0, mixed $job = null): ?array
    {
        return match (true) {
            $this->lostConnection => [static::EXIT_SUCCESS, WorkerStopReason::LostConnection],
            $this->shouldQuit => [static::EXIT_SUCCESS, WorkerStopReason::Interrupted],
            $this->memoryExceeded($options->memory) => [static::$memoryExceededExitCode ?? static::EXIT_MEMORY_LIMIT, WorkerStopReason::MaxMemoryExceeded],
            $this->queueShouldRestart($lastRestart) => [static::EXIT_SUCCESS, WorkerStopReason::ReceivedRestartSignal],
            $options->stopWhenEmpty && is_null($job) => [static::EXIT_SUCCESS, WorkerStopReason::QueueEmpty],
            $options->maxTime && hrtime(true) / 1e9 - $startTime >= $options->maxTime => [static::EXIT_SUCCESS, WorkerStopReason::MaxTimeExceeded],
            $options->maxJobs && $jobsProcessed >= $options->maxJobs => [static::EXIT_SUCCESS, WorkerStopReason::MaxJobsExceeded],
            default => null
        };
    }

    /**
     * Process the next job on the queue.
     */
    public function runNextJob(string $connectionName, string $queue, WorkerOptions $options): void
    {
        $job = $this->getNextJob(
            $this->manager->connection($connectionName), $queue
        );

        // If we're able to pull a job off of the stack, we will process it and then return
        // from this method. If there is no job on the queue, we will "sleep" the worker
        // for the specified number of seconds, then keep processing jobs after sleep.
        if ($job) {
            return $this->runJob($job, $connectionName, $options);
        }

        $this->sleep($options->sleep);
    }

    /**
     * Get the next job from the queue connection.
     */
    protected function getNextJob(Queue $connection, string $queue): ?Job
    {
        $popJobCallback = function ($queue, $index = 0) use ($connection) {
            return $connection->pop($queue, $index);
        };

        $this->raiseBeforeJobPopEvent($connection->getConnectionName(), $queue);

        try {
            if (isset(static::$popCallbacks[$this->name ?? ''])) {
                if (! is_null($job = (static::$popCallbacks[$this->name ?? ''])($popJobCallback, $queue))) {
                    $this->raiseAfterJobPopEvent($connection->getConnectionName(), $job);
                }

                return $job;
            }

            foreach (explode(',', $queue) as $index => $queue) {
                if ($this->queuePaused($connection->getConnectionName(), $queue)) {
                    continue;
                }

                if (! is_null($job = $popJobCallback($queue, $index))) {
                    $this->raiseAfterJobPopEvent($connection->getConnectionName(), $job);

                    return $job;
                }
            }
        } catch (Throwable $e) {
			logger()->error($e->getMessage());
            // $this->exceptions->report($e);

            $this->stopWorkerIfLostConnection($e);

            $this->sleep(1);
        }

		return null;
    }

    /**
     * Determine if a given connection and queue is paused.
     */
    protected function queuePaused(string $connectionName, string $queue): bool
    {
        if (! static::$pausable) {
            return false;
        }

        return $this->cache && $this->manager->isPaused($connectionName, $queue);
    }

    /**
     * Process the given job.
     */
    protected function runJob(Job $job, string $connectionName, WorkerOptions $options): void
    {
        try {
            return $this->process($connectionName, $job, $options);
        } catch (Throwable $e) {
            if (static::$reportJobExceptions) {
				logger()->error($e->getMessage());
                // $this->exceptions->report($e);
            }

            $this->stopWorkerIfLostConnection($e);
        }
    }

    /**
     * Stop the worker if we have lost connection to a database.
     */
    protected function stopWorkerIfLostConnection(Throwable $e): void
    {
		/*
        if ($this->causedByLostConnection($e)) {
            $this->lostConnection = true;
        }
		*/
    }

    /**
     * Process the given job from the queue.
     *
     * @throws Throwable
     */
    public function process(string $connectionName, Job $job, WorkerOptions $options): void
    {
        try {
            // First we will raise the before job event and determine if the job has already run
            // over its maximum attempt limits, which could primarily happen when this job is
            // continually timing out and not actually throwing any exceptions from itself.
            $this->raiseBeforeJobEvent($connectionName, $job);

            $this->markJobAsFailedIfAlreadyExceedsMaxAttempts(
                $connectionName, $job, (int) $options->maxTries
            );

            if ($job->isDeleted()) {
                return $this->raiseAfterJobEvent($connectionName, $job);
            }

            // Here we will fire off the job and let it process. We will catch any exceptions, so
            // they can be reported to the developer's logs, etc. Once the job is finished the
            // proper events will be fired to let any listeners know this job has completed.
            $job->fire();

            $this->raiseAfterJobEvent($connectionName, $job);
        } catch (Throwable $e) {
            $exceptionOccurred = $e;

            $this->handleJobException($connectionName, $job, $options, $e);
        } finally {
			$this->events->jobAttempted($connectionName, $job, $exceptionOccurred ?? null);
        }
    }

    /**
     * Handle an exception that occurred while the job was running.
     *
     * @throws Throwable
     */
    protected function handleJobException(string $connectionName, Job $job, WorkerOptions $options, Throwable $e): void
    {
        try {
            // First, we will go ahead and mark the job as failed if it will exceed the maximum
            // attempts it is allowed to run the next time we process it. If so we will just
            // go ahead and mark it as failed now so we do not have to release this again.
            if (! $job->hasFailed()) {
                $this->markJobAsFailedIfWillExceedMaxAttempts(
                    $connectionName, $job, (int) $options->maxTries, $e
                );

                $this->markJobAsFailedIfWillExceedMaxExceptions(
                    $connectionName, $job, $e
                );
            }

            $this->raiseExceptionOccurredJobEvent(
                $connectionName, $job, $e
            );
        } finally {
            // If we catch an exception, we will attempt to release the job back onto the queue
            // so it is not lost entirely. This'll let the job be retried at a later time by
            // another listener (or this same one). We will re-throw this exception after.
            if (! $job->isDeleted() && ! $job->isReleased() && ! $job->hasFailed()) {
                $backoff = $this->calculateBackoff($job, $options);

                $job->release($backoff);

				$this->events->jobReleasedAfterException($connectionName, $job, $backoff);
            }
        }

        throw $e;
    }

    /**
     * Mark the given job as failed if it has exceeded the maximum allowed attempts.
     *
     * This will likely be because the job previously exceeded a timeout.
     *
     * @throws Throwable
     */
    protected function markJobAsFailedIfAlreadyExceedsMaxAttempts(string $connectionName, Job $job, int $maxTries): void
    {
        $maxTries = ! is_null($job->maxTries()) ? $job->maxTries() : $maxTries;

        $retryUntil = $job->retryUntil();

        if ($retryUntil && Date::now()->getTimestamp() <= $retryUntil) {
            return;
        }

        if (! $retryUntil && ($maxTries === 0 || $job->attempts() <= $maxTries)) {
            return;
        }

        $this->failJob($job, $e = $this->maxAttemptsExceededException($job));

        throw $e;
    }

    /**
     * Mark the given job as failed if it has exceeded the maximum allowed attempts.
     */
    protected function markJobAsFailedIfWillExceedMaxAttempts(string $connectionName, Job $job, int $maxTries, Throwable $e): void
    {
        $maxTries = ! is_null($job->maxTries()) ? $job->maxTries() : $maxTries;

        if ($job->retryUntil() && $job->retryUntil() <= Date::now()->getTimestamp()) {
            $this->failJob($job, $e);
        }

        if (! $job->retryUntil() && $maxTries > 0 && $job->attempts() >= $maxTries) {
            $this->failJob($job, $e);
        }
    }

    /**
     * Mark the given job as failed if it has exceeded the maximum allowed attempts.
     */
    protected function markJobAsFailedIfWillExceedMaxExceptions(string $connectionName, Job $job, Throwable $e): void
    {
        if (! $this->cache || is_null($uuid = $job->uuid()) ||
            is_null($maxExceptions = $job->maxExceptions())) {
            return;
        }

        if (! $this->cache->get('job-exceptions:'.$uuid)) {
            $this->cache->set('job-exceptions:'.$uuid, 0, Date::now()->addDay()->getTimestamp());
        }

        if ($maxExceptions <= $this->cache->increment('job-exceptions:'.$uuid)) {
            $this->cache->delete('job-exceptions:'.$uuid);

            $this->failJob($job, $e);
        }
    }

    /**
     * Mark the given job as failed if it should fail on timeouts.
     */
    protected function markJobAsFailedIfItShouldFailOnTimeout(string $connectionName, Job $job, Throwable $e): void
    {
        if (method_exists($job, 'shouldFailOnTimeout') ? $job->shouldFailOnTimeout() : false) {
            $this->failJob($job, $e);
        }
    }

    /**
     * Mark the given job as failed and raise the relevant event.
     */
    protected function failJob(Job $job, Throwable $e): void
    {
        $job->fail($e);
    }

    /**
     * Calculate the backoff for the given job.
     */
    protected function calculateBackoff(Job $job, WorkerOptions $options): int
    {
        $backoff = explode(
            ',',
            method_exists($job, 'backoff') && ! is_null($job->backoff())
                ? $job->backoff()
                : $options->backoff
        );

        return (int) ($backoff[$job->attempts() - 1] ?? last($backoff));
    }

    /**
     * Raise an event indicating the worker is starting.
     */
    protected function raiseWorkerStartingEvent(string $connectionName, string $queue, WorkerOptions $options): void
    {
		$this->events->workerStarting($connectionName, $queue, $options);
    }

    /**
     * Raise an event indicating a job is being popped from the queue.
     */
    protected function raiseBeforeJobPopEvent(string $connectionName, ?string $queue = null): void
    {
		$this->events->jobPopping($connectionName, $queue);
    }

    /**
     * Raise an event indicating a job has been popped from the queue.
     */
    protected function raiseAfterJobPopEvent(string $connectionName, ?Job $job): void
    {
		$this->events->jobPopped($connectionName, $job);
    }

    /**
     * Raise an event indicating a job is being processed.
     */
    protected function raiseBeforeJobEvent(string $connectionName, Job $job): void
    {
		$this->events->jobProcessing($connectionName, $job);
    }

    /**
     * Raise an event indicating a job has been processed.
     */
    protected function raiseAfterJobEvent(string $connectionName, Job $job): void
    {
		$this->events->jobProcessed($connectionName, $job);
    }

    /**
     * Raise the exception occurred queue job event.
     */
    protected function raiseExceptionOccurredJobEvent(string $connectionName, Job $job, Throwable $e): void
    {
		$this->events->jobExceptionOccured($connectionName, $job, $e);
    }

    /**
     * Determine if the queue worker should restart.
     */
    protected function queueShouldRestart(?int $lastRestart): bool
    {
        if (! static::$restartable) {
            return false;
        }

        return $this->getTimestampOfLastQueueRestart() != $lastRestart;
    }

    /**
     * Get the last queue restart timestamp, or null.
     */
    protected function getTimestampOfLastQueueRestart(): ?int
    {
        if (! static::$restartable) {
            return null;
        }

        if ($this->cache) {
            return (int) $this->cache->get('blitzphp:queue:restart');
        }

		return null;
    }

    /**
     * Enable async signals for the process.
     */
    protected function listenForSignals(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGQUIT, fn () => $this->shouldQuit = true);
        pcntl_signal(SIGTERM, fn () => $this->shouldQuit = true);
        pcntl_signal(SIGINT, fn () => $this->shouldQuit = true);
        pcntl_signal(SIGUSR2, fn () => $this->paused = true);
        pcntl_signal(SIGCONT, fn () => $this->paused = false);
    }

    /**
     * Determine if "async" signals are supported.
     */
    protected function supportsAsyncSignals(): bool
    {
        return extension_loaded('pcntl');
    }

    /**
     * Determine if the memory limit has been exceeded.
     */
    public function memoryExceeded(int $memoryLimit): bool
    {
        return ((int) $memoryLimit) > 0 && (memory_get_usage(true) / 1024 / 1024) >= ((int) $memoryLimit);
    }

    /**
     * Stop listening and bail out of the script.
     */
    public function stop(int $status = 0, ?WorkerOptions $options = null, ?WorkerStopReason $reason = null): int
    {
		$this->events->workerStopping($this->manager->getName(), $status, $options, $reason);

        return $status;
    }

    /**
     * Kill the process.
     */
    public function kill(int $status = 0, ?WorkerOptions $options = null, ?WorkerStopReason $reason = null): never
    {
		$status = $this->stop($status, $options, $reason);

        if (extension_loaded('posix')) {
            posix_kill(getmypid(), SIGKILL);
        }

        exit($status);
    }

    /**
     * Create an instance of MaxAttemptsExceededException.
     */
    protected function maxAttemptsExceededException(Job $job): MaxAttemptsExceededException
    {
        return MaxAttemptsExceededException::forJob($job);
    }

    /**
     * Create an instance of TimeoutExceededException.
     */
    protected function timeoutExceededException(Job $job): TimeoutExceededException
    {
        return TimeoutExceededException::forJob($job);
    }

    /**
     * Sleep the script for a given number of seconds.
     */
    public function sleep(int|float $seconds): void
    {
        if ($seconds < 1) {
            usleep($seconds * 1_000_000);
        } else {
            sleep($seconds);
        }
    }

    /**
     * Set the cache repository implementation.
     */
    public function setCache(Cache $cache): self
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * Set the name of the worker.
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Register a callback to be executed to pick jobs.
     */
    public static function popUsing(string $workerName, callable $callback): void
    {
        if (is_null($callback)) {
            unset(static::$popCallbacks[$workerName]);
        } else {
            static::$popCallbacks[$workerName] = $callback;
        }
    }

    /**
     * Get the queue manager instance.
     */
    public function getManager(): Manager
    {
        return $this->manager;
    }

    /**
     * Set the queue manager instance.
     */
    public function setManager(Manager $manager): void
    {
        $this->manager = $manager;
    }
}
