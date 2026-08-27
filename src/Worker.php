<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue;

use BlitzPHP\Contracts\Cache\CacheInterface;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Contracts\Queue\Queue;
use BlitzPHP\Queue\DTO\WorkerOptions;
use BlitzPHP\Queue\Enums\WorkerStopReason;
use BlitzPHP\Queue\Events\QueueEventManager;
use BlitzPHP\Queue\Exceptions\MaxAttemptsExceededException;
use BlitzPHP\Queue\Exceptions\TimeoutExceededException;
use BlitzPHP\Utilities\Date;
use Illuminate\Contracts\Debug\ExceptionHandler;
// use BlitzPHP\Database\DetectsLostConnections; // disponible uniquement dans blitz-php/database 1.1
use Throwable;

/**
 * Worker de file d'attente.
 *
 * Prélève et exécute les jobs en boucle (daemon) ou un par un, gère les
 * timeouts, les tentatives, la mémoire et les signaux POSIX.
 */
class Worker
{
    // use DetectsLostConnections;

    /**
     * Code de sortie en cas de succès.
     */
    public const EXIT_SUCCESS = EXIT_SUCCESS;

    /**
     * Code de sortie en cas d'erreur.
     */
    public const EXIT_ERROR = EXIT_ERROR;

    /**
     * Code de sortie en cas de dépassement de la limite mémoire.
     */
    public const EXIT_MEMORY_LIMIT = 12;

    /**
     * Nom du worker.
     */
    protected ?string $name;

    /**
     * Implémentation du dépôt de cache.
     */
    protected CacheInterface $cache;

    /**
     * Gestionnaire d'exceptions (contrat Illuminate).
     *
     * @var ExceptionHandler
     */
    protected $exceptions;

    /**
     * Callback indiquant si l'application est en maintenance.
     *
     * @var callable
     */
    protected $isDownForMaintenance;

    /**
     * Callback de réinitialisation du périmètre applicatif entre deux jobs.
     *
     * @var callable
     */
    protected $resetScope;

    /**
     * Indique si le worker doit s'arrêter.
     */
    public bool $shouldQuit = false;

    /**
     * Indique si le worker a perdu sa connexion.
     */
    public bool $lostConnection = false;

    /**
     * Indique si le worker est en pause.
     */
    public bool $paused = false;

    /**
     * Callbacks utilisés pour prélever les jobs.
     *
     * @var list<callable>
     */
    protected static array $popCallbacks = [];

    /**
     * Code de sortie personnalisé en cas de dépassement mémoire.
     */
    public static ?int $memoryExceededExitCode = null;

    /**
     * Indique si les exceptions de job doivent être journalisées.
     */
    public static bool $reportJobExceptions = true;

    /**
     * Indique si le worker doit consulter le signal de redémarrage en cache.
     */
    public static bool $restartable = true;

    /**
     * Indique si le worker doit consulter le signal de pause en cache.
     */
    public static bool $pausable = true;

    /**
     * Crée un worker de file d'attente.
     *
     * @param Manager           $manager    Instance du gestionnaire de files.
     * @param QueueEventManager $events     Instance du gestionnaire d'événements de file.
     * @param ExceptionHandler  $exceptions
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
        $this->resetScope           = $resetScope;
    }

    /**
     * Écoute la file donnée en boucle (mode daemon).
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
            // Avant de réserver un job, on vérifie que la file n'est pas en pause.
            if (! $this->daemonShouldRun($options, $connectionName, $queue)) {
                [$status, $reason] = $this->pauseWorker($options, $lastRestart);

                if (null !== $status) {
                    return $this->stop($status, $options, $reason);
                }

                continue;
            }

            if (isset($this->resetScope)) {
                ($this->resetScope)();
            }

            // Prélèvement du prochain job, enregistrement du timeout, puis exécution.
            $job = $this->getNextJob(
                $this->manager->driver($connectionName),
                $queue,
            );

            if ($supportsAsyncSignals) {
                $this->registerTimeoutHandler($job, $options);
            }

            // Si un job est disponible, on le traite ; sinon on attend avant de resonder.
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

            // Arrêt si limite mémoire, signal de redémarrage, file vide, max jobs/temps, etc.
            [$status, $reason] = $this->stopIfNecessary(
                $options,
                $lastRestart,
                $startTime,
                $jobsProcessed,
                $job,
            );

            if (null !== $status) {
                return $this->stop($status, $options, $reason);
            }
        }
    }

    /**
     * Enregistre le gestionnaire de dépassement de délai du worker.
     */
    protected function registerTimeoutHandler(Job $job, WorkerOptions $options): void
    {
        // Gestionnaire SIGALRM : interrompt un job bloqué trop longtemps (signaux async PHP).
        pcntl_signal(SIGALRM, function () use ($job, $options) {
            if ($job) {
                $this->markJobAsFailedIfWillExceedMaxAttempts(
                    $job->getConnectionName(),
                    $job,
                    (int) $options->maxTries,
                    $e = $this->timeoutExceededException($job),
                );

                $this->markJobAsFailedIfWillExceedMaxExceptions(
                    $job->getConnectionName(),
                    $job,
                    $e,
                );

                $this->markJobAsFailedIfItShouldFailOnTimeout(
                    $job->getConnectionName(),
                    $job,
                    $e,
                );

                $this->events->jobTimeout($job->getConnectionName(), $job->getQueue(), $job);
            }

            $this->kill(static::EXIT_ERROR, $options, WorkerStopReason::TimedOut);
        }, true);

        pcntl_alarm(
            max($this->timeoutForJob($job, $options), 0),
        );
    }

    /**
     * Réinitialise le gestionnaire de dépassement de délai.
     */
    protected function resetTimeoutHandler(): void
    {
        pcntl_alarm(0);
    }

    /**
     * Retourne le délai d'exécution applicable au job.
     */
    protected function timeoutForJob(Job $job, WorkerOptions $options): int
    {
        return $job && null !== $job->timeout() ? $job->timeout() : $options->timeout;
    }

    /**
     * Indique si le daemon doit traiter un job à cette itération.
     */
    protected function daemonShouldRun(WorkerOptions $options, string $connectionName, string $queue): bool
    {
        return ! (($this->isDownForMaintenance)() && ! $options->force)
            || $this->paused;
    }

    /**
     * Met le worker en pause pour l'itération courante.
     */
    protected function pauseWorker(WorkerOptions $options, int $lastRestart): ?array
    {
        $this->sleep($options->sleep > 0 ? $options->sleep : 1);

        return $this->stopIfNecessary($options, $lastRestart);
    }

    /**
     * Détermine le code de sortie si le processus doit s'arrêter.
     */
    protected function stopIfNecessary(WorkerOptions $options, int $lastRestart, float|int $startTime = 0, int $jobsProcessed = 0, mixed $job = null): ?array
    {
        return match (true) {
            $this->lostConnection                                                     => [static::EXIT_SUCCESS, WorkerStopReason::LostConnection],
            $this->shouldQuit                                                         => [static::EXIT_SUCCESS, WorkerStopReason::Interrupted],
            $this->memoryExceeded($options->memory)                                   => [static::$memoryExceededExitCode ?? static::EXIT_MEMORY_LIMIT, WorkerStopReason::MaxMemoryExceeded],
            $this->queueShouldRestart($lastRestart)                                   => [static::EXIT_SUCCESS, WorkerStopReason::ReceivedRestartSignal],
            $options->stopWhenEmpty && null === $job                                  => [static::EXIT_SUCCESS, WorkerStopReason::QueueEmpty],
            $options->maxTime && hrtime(true) / 1e9 - $startTime >= $options->maxTime => [static::EXIT_SUCCESS, WorkerStopReason::MaxTimeExceeded],
            $options->maxJobs && $jobsProcessed >= $options->maxJobs                  => [static::EXIT_SUCCESS, WorkerStopReason::MaxJobsExceeded],
            default                                                                   => null,
        };
    }

    /**
     * Traite le prochain job de la file.
     */
    public function runNextJob(string $connectionName, string $queue, WorkerOptions $options): void
    {
        $job = $this->getNextJob(
            $this->manager->connection($connectionName),
            $queue,
        );

        // Job disponible : traitement immédiat. File vide : pause puis nouvelle tentative.
        if ($job) {
            $this->runJob($job, $connectionName, $options);

            return;
        }

        $this->sleep($options->sleep);
    }

    /**
     * Prélève le prochain job via le pilote de file.
     */
    protected function getNextJob(Queue $driver, string $queue): ?Job
    {
        $popJobCallback = fn ($queue, $index = 0) => $driver->pop($queue, $index);

        $this->raiseBeforeJobPopEvent($driver->getConnectionName(), $queue);

        try {
            if (isset(static::$popCallbacks[$this->name ?? ''])) {
                if (null !== ($job = (static::$popCallbacks[$this->name ?? ''])($popJobCallback, $queue))) {
                    $this->raiseAfterJobPopEvent($driver->getConnectionName(), $job);
                }

                return $job;
            }

            foreach (explode(',', $queue) as $index => $queue) {
                if ($this->queuePaused($driver->getConnectionName(), $queue)) {
                    continue;
                }

                if (null !== ($job = $popJobCallback($queue, $index))) {
                    $this->raiseAfterJobPopEvent($driver->getConnectionName(), $job);

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
     * Indique si la file de la connexion donnée est en pause.
     */
    protected function queuePaused(string $connectionName, string $queue): bool
    {
        if (! static::$pausable) {
            return false;
        }

        return $this->cache && $this->manager->isPaused($connectionName, $queue);
    }

    /**
     * Traite le job donné.
     */
    protected function runJob(Job $job, string $connectionName, WorkerOptions $options): void
    {
        try {
            $this->process($connectionName, $job, $options);
        } catch (Throwable $e) {
            if (static::$reportJobExceptions) {
                logger()->error($e->getMessage());
                // $this->exceptions->report($e);
            }

            $this->stopWorkerIfLostConnection($e);
        }
    }

    /**
     * Arrête le worker si la connexion base de données est perdue.
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
     * Traite le job prélevé de la file.
     *
     * @throws Throwable
     */
    public function process(string $connectionName, Job $job, WorkerOptions $options): void
    {
        try {
            // Événement « avant job » puis contrôle du nombre maximal de tentatives.
            $this->raiseBeforeJobEvent($connectionName, $job);

            $this->markJobAsFailedIfAlreadyExceedsMaxAttempts(
                $connectionName,
                $job,
                (int) $options->maxTries,
            );

            if ($job->isDeleted()) {
                $this->raiseAfterJobEvent($connectionName, $job);

                return;
            }

            // Exécution du job ; les exceptions sont capturées pour journalisation et relâchement.
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
     * Traite une exception survenue pendant l'exécution du job.
     *
     * @throws Throwable
     */
    protected function handleJobException(string $connectionName, Job $job, WorkerOptions $options, Throwable $e): void
    {
        try {
            // Marque le job en échec s'il dépassera le quota de tentatives à la prochaine exécution.
            if (! $job->hasFailed()) {
                $this->markJobAsFailedIfWillExceedMaxAttempts(
                    $connectionName,
                    $job,
                    (int) $options->maxTries,
                    $e,
                );

                $this->markJobAsFailedIfWillExceedMaxExceptions(
                    $connectionName,
                    $job,
                    $e,
                );
            }

            $this->raiseExceptionOccurredJobEvent(
                $connectionName,
                $job,
                $e,
            );
        } finally {
            // Relâche le job dans la file pour une tentative ultérieure, puis relance l'exception.
            if (! $job->isDeleted() && ! $job->isReleased() && ! $job->hasFailed()) {
                $backoff = $this->calculateBackoff($job, $options);

                $job->release($backoff);

                $this->events->jobReleasedAfterException($connectionName, $job, $backoff);
            }
        }

        throw $e;
    }

    /**
     * Marque le job en échec s'il a dépassé le nombre maximal de tentatives.
     *
     * Souvent dû à un dépassement de délai lors d'une tentative précédente.
     *
     * @throws Throwable
     */
    protected function markJobAsFailedIfAlreadyExceedsMaxAttempts(string $connectionName, Job $job, int $maxTries): void
    {
        $maxTries = null !== $job->maxTries() ? $job->maxTries() : $maxTries;

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
     * Marque le job en échec s'il a dépassé le nombre maximal de tentatives.
     */
    protected function markJobAsFailedIfWillExceedMaxAttempts(string $connectionName, Job $job, int $maxTries, Throwable $e): void
    {
        $maxTries = null !== $job->maxTries() ? $job->maxTries() : $maxTries;

        if ($job->retryUntil() && $job->retryUntil() <= Date::now()->getTimestamp()) {
            $this->failJob($job, $e);
        }

        if (! $job->retryUntil() && $maxTries > 0 && $job->attempts() >= $maxTries) {
            $this->failJob($job, $e);
        }
    }

    /**
     * Marque le job en échec s'il a dépassé le nombre maximal de tentatives.
     */
    protected function markJobAsFailedIfWillExceedMaxExceptions(string $connectionName, Job $job, Throwable $e): void
    {
        if (! $this->cache || null === ($uuid = $job->uuid())
                           || null === ($maxExceptions = $job->maxExceptions())) {
            return;
        }

        if (! $this->cache->get('job-exceptions-' . $uuid)) {
            $this->cache->set('job-exceptions-' . $uuid, 0, Date::now()->addDay()->getTimestamp());
        }

        if ($maxExceptions <= $this->cache->increment('job-exceptions-' . $uuid)) {
            $this->cache->delete('job-exceptions-' . $uuid);

            $this->failJob($job, $e);
        }
    }

    /**
     * Marque le job en échec s'il doit échouer au timeout.
     */
    protected function markJobAsFailedIfItShouldFailOnTimeout(string $connectionName, Job $job, Throwable $e): void
    {
        if (method_exists($job, 'shouldFailOnTimeout') ? $job->shouldFailOnTimeout() : false) {
            $this->failJob($job, $e);
        }
    }

    /**
     * Marque le job en échec et émet l'événement correspondant.
     */
    protected function failJob(Job $job, Throwable $e): void
    {
        $job->fail($e);
    }

    /**
     * Calcule le délai de retry du job.
     */
    protected function calculateBackoff(Job $job, WorkerOptions $options): int
    {
        $backoff = explode(
            ',',
            method_exists($job, 'backoff') && null !== $job->backoff()
                ? $job->backoff()
                : $options->backoff,
        );

        return (int) ($backoff[$job->attempts() - 1] ?? last($backoff));
    }

    /**
     * Émet l'événement de démarrage du worker.
     */
    protected function raiseWorkerStartingEvent(string $connectionName, string $queue, WorkerOptions $options): void
    {
        $this->events->workerStarting($connectionName, $queue, $options);
    }

    /**
     * Émet l'événement de prélèvement imminent.
     */
    protected function raiseBeforeJobPopEvent(string $connectionName, ?string $queue = null): void
    {
        $this->events->jobPopping($connectionName, $queue);
    }

    /**
     * Émet l'événement de job prélevé.
     */
    protected function raiseAfterJobPopEvent(string $connectionName, ?Job $job): void
    {
        $this->events->jobPopped($connectionName, $job);
    }

    /**
     * Émet l'événement de traitement en cours.
     */
    protected function raiseBeforeJobEvent(string $connectionName, Job $job): void
    {
        $this->events->jobProcessing($connectionName, $job);
    }

    /**
     * Émet l'événement de job traité.
     */
    protected function raiseAfterJobEvent(string $connectionName, Job $job): void
    {
        $this->events->jobProcessed($connectionName, $job);
    }

    /**
     * Émet l'événement d'exception survenue sur un job.
     */
    protected function raiseExceptionOccurredJobEvent(string $connectionName, Job $job, Throwable $e): void
    {
        $this->events->jobExceptionOccured($connectionName, $job, $e);
    }

    /**
     * Indique si le worker doit redémarrer.
     */
    protected function queueShouldRestart(?int $lastRestart): bool
    {
        if (! static::$restartable) {
            return false;
        }

        return $this->getTimestampOfLastQueueRestart() !== $lastRestart;
    }

    /**
     * Retourne l'horodatage du dernier signal de redémarrage, ou null.
     */
    protected function getTimestampOfLastQueueRestart(): ?int
    {
        if (! static::$restartable) {
            return null;
        }

        if ($this->cache) {
            return (int) $this->cache->get('blitzphp-queue-restart');
        }

        return null;
    }

    /**
     * Active la gestion asynchrone des signaux pour le processus.
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
     * Indique si les signaux asynchrones sont disponibles.
     */
    protected function supportsAsyncSignals(): bool
    {
        return extension_loaded('pcntl');
    }

    /**
     * Indique si la limite mémoire a été dépassée.
     */
    public function memoryExceeded(int $memoryLimit): bool
    {
        return ((int) $memoryLimit) > 0 && (memory_get_usage(true) / 1024 / 1024) >= ((int) $memoryLimit);
    }

    /**
     * Arrête l'écoute et quitte le script.
     */
    public function stop(int $status = 0, ?WorkerOptions $options = null, ?WorkerStopReason $reason = null): int
    {
        $this->events->workerStopping($this->manager->getName(), $status, $options, $reason);

        return $status;
    }

    /**
     * Termine le processus.
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
     * Crée une instance de MaxAttemptsExceededException.
     */
    protected function maxAttemptsExceededException(Job $job): MaxAttemptsExceededException
    {
        return MaxAttemptsExceededException::forJob($job);
    }

    /**
     * Crée une instance de TimeoutExceededException.
     */
    protected function timeoutExceededException(Job $job): TimeoutExceededException
    {
        return TimeoutExceededException::forJob($job);
    }

    /**
     * Met le script en pause pendant un nombre de secondes donné.
     */
    public function sleep(float|int $seconds): void
    {
        if ($seconds < 1) {
            usleep($seconds * 1_000_000);
        } else {
            sleep($seconds);
        }
    }

    /**
     * Définit l'implémentation du cache.
     */
    public function setCache(CacheInterface $cache): self
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * Définit le nom du worker.
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Enregistre un callback de prélèvement des jobs.
     */
    public static function popUsing(string $workerName, callable $callback): void
    {
        if (null === $callback) {
            unset(static::$popCallbacks[$workerName]);
        } else {
            static::$popCallbacks[$workerName] = $callback;
        }
    }

    /**
     * Retourne le gestionnaire de files.
     */
    public function getManager(): Manager
    {
        return $this->manager;
    }

    /**
     * Définit le gestionnaire de files.
     */
    public function setManager(Manager $manager): void
    {
        $this->manager = $manager;
    }
}
