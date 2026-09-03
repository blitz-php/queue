<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Commands;

use BlitzPHP\Cli\Console\Command;
use BlitzPHP\Cli\Console\Console;
use BlitzPHP\Contracts\Cache\CacheInterface;
use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Contracts\Queue\Job;
use BlitzPHP\Queue\DTO\WorkerOptions;
use BlitzPHP\Queue\Events\QueueEvent;
use BlitzPHP\Queue\Events\QueueEventManager;
use BlitzPHP\Queue\Worker;
use BlitzPHP\Traits\Support\InteractsWithTime;
use BlitzPHP\Utilities\Date;
use BlitzPHP\Utilities\String\Stringable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Commande console `queue:work` : traite les jobs en daemon ou un par un.
 */
class Work extends Command
{
    use InteractsWithTime;

    /**
     * @var string Groupe auquel appartient la commande
     */
    protected $group = 'Queue';

    /**
     * @var string Nom de la commande
     */
    protected $name = 'queue:work';

    /**
     * @var string Description de la commande
     */
    protected $description = 'Traite les jobs de la file d\'attente en mode daemon';

    /**
     * @var array Arguments de la commande
     */
    protected $arguments = [
        'connection' => 'Nom de la connexion de file à traiter',
    ];

    /**
     * @var array Options de la commande
     */
    protected $options = [
        '--name'            => ['Nom du worker', 'default'],
        '--queue'           => ['Noms des files à traiter (séparés par des virgules)'],
        '--daemon'          => ['Exécute le worker en mode daemon (obsolète)'],
        '--once'            => ['Ne traite que le prochain job de la file'],
        '--stop-when-empty' => ['S\'arrête lorsque la file est vide'],
        '--delay'           => ['Secondes de délai avant retry d\'un job échoué (obsolète)', 0],
        '--backoff'         => ['Secondes d\'attente avant de relancer un job ayant levé une exception', 0],
        '--max-jobs'        => ['Nombre de jobs à traiter avant arrêt', 0],
        '--max-time'        => ['Durée maximale d\'exécution du worker (secondes)', 0],
        '--force'           => ['Force l\'exécution même en mode maintenance'],
        '--memory'          => ['Limite mémoire en mégaoctets', 128],
        '--sleep'           => ['Secondes d\'attente lorsqu\'aucun job n\'est disponible', 3],
        '--rest'            => ['Secondes de pause entre deux jobs', 0],
        '--timeout'         => ['Durée maximale d\'un processus enfant (secondes)', 60],
        '--tries'           => ['Nombre de tentatives avant d\'enregistrer l\'échec', 1],
        '--json'            => ['Affiche les informations du worker au format JSON'],
    ];

    /**
     * Instance du worker de file.
     */
    protected Worker $worker;

    /**
     * Implémentation du cache.
     */
    protected CacheInterface $cache;

    /**
     * Gestionnaire d'événements de l'application.
     */
    protected EventManagerInterface $events;

    /**
     * Horodatage de début du dernier job traité, s'il y en a un.
     */
    protected ?float $latestStartedAt = null;

    /**
     * Indique si les écouteurs d'événements du worker ont été enregistrés.
     */
    private static bool $hasRegisteredListeners = false;

    /**
     * Indique si `stty` est disponible (null = pas encore sondé).
     */
    private static ?bool $stty = null;

    /**
     * Crée la commande de traitement de la file.
     */
    public function __construct(protected ContainerInterface $container, protected Console $app)
    {
        parent::__construct($app, $container->get(LoggerInterface::class));

        $this->worker = service('worker');
        $this->cache  = $container->get(CacheInterface::class);
        $this->events = $container->get(EventManagerInterface::class);
    }

    /**
     * Exécute la commande console.
     *
     * @return int|null
     */
    public function execute(array $params)
    {
        set_time_limit(0);

        if ($this->downForMaintenance() && $this->option('once')) {
            return $this->worker->sleep($this->option('sleep'));
        }

        // Écoute des événements de succès / échec pour afficher la progression en console.
        $this->listenForEvents();

        $connection = $this->argument('connection') ?: config('queue.default');

        // File cible : option --queue, sinon valeur de configuration de la connexion.
        $queue = $this->getQueue($connection);

        if (! $this->outputUsingJson() && static::terminalHasSttyAvailable()) {
            $this->info(
                sprintf('Processing jobs from the [%s] %s.', $queue, (new Stringable('queue'))->plural(explode(',', $queue))),
            );
        }

        return $this->runWorker(
            $connection,
            $queue,
        );
    }

    /**
     * Lance l'instance du worker.
     */
    protected function runWorker(string $connection, string $queue): ?int
    {
        return $this->worker
            ->setName($this->option('name', 'default'))
            ->setCache($this->cache)
            ->{$this->option('once') ? 'runNextJob' : 'daemon'}(
                $connection,
                $queue,
                $this->gatherWorkerOptions()
            );
    }

    /**
     * Regroupe les options du worker dans un seul objet.
     */
    protected function gatherWorkerOptions(): WorkerOptions
    {
        return new WorkerOptions(
            $this->option('name', 'default'),
            max($this->option('backoff', 0), $this->option('delay', 0)),
            $this->option('memory', 128),
            $this->option('timeout', 60),
            $this->option('sleep', 3),
            $this->option('tries', 1),
            $this->option('force', false),
            $this->option('stop-when-empty', false),
            $this->option('max-jobs', 0),
            $this->option('max-time', 0),
            $this->option('rest', 0),
        );
    }

    /**
     * Écoute les événements de file pour mettre à jour la sortie console.
     */
    protected function listenForEvents(): void
    {
        if (static::$hasRegisteredListeners) {
            return;
        }

        $this->events->on(QueueEventManager::JOB_PROCESSING, function (QueueEvent $event) {
            $this->writeOutput($event->job, 'starting');
        });

        $this->events->on(QueueEventManager::JOB_PROCESSED, function (QueueEvent $event) {
            $this->writeOutput($event->job, 'success');
        });

        $this->events->on(QueueEventManager::JOB_RELEASED_AFTER_EXCEPTION, function (QueueEvent $event) {
            $this->writeOutput($event->job, 'released_after_exception');
        });

        $this->events->on(QueueEventManager::JOB_FAILED, function (QueueEvent $event) {
            $this->writeOutput($event->job, 'failed', $event->exception);

            $this->logFailedJob($event);
        });

        static::$hasRegisteredListeners = true;
    }

    /**
     * Affiche l'état du worker (JSON ou TTY).
     */
    protected function writeOutput(Job $job, string $status, ?Throwable $exception = null): void
    {
        if ($this->isSilent()) {
            return;
        }

        $this->outputUsingJson()
            ? $this->writeOutputAsJson($job, $status, $exception)
            : $this->writeOutputForCli($job, $status);
    }

    /**
     * Affiche l'état du worker dans le terminal.
     */
    protected function writeOutputForCli(Job $job, string $status): void
    {
        $isVerbose = $this->option('verbose');

        $first = sprintf(
            '%s %s %s',
            $this->color->comment($this->now()->format('Y-m-d H:i:s')),
            $job->resolveName(),
            ! $isVerbose ? '' : sprintf(
                '%s %s',
                $this->color->comment($job->getJobId()),
                $this->color->info($job->getConnectionName() . ' ' . $job->getQueue()),
            ),
        );

        if ($status === 'starting') {
            $this->latestStartedAt = microtime(true);

            $second = $this->color->warn('RUNNING', ['bold' => 1]);
        } else {
            $runTime = (microtime(true) - $this->latestStartedAt) * 1000;
            $runTime = (float) number_format($runTime, 2, '.', '');

            $memory = $isVerbose ? round(memory_get_usage(true) / 1024 / 1024, 1) . 'MB' : '';

            $second = $this->color->comment("{$runTime} ms" . ($memory ? " {$memory}" : '') . ' ');
            $second .= match ($status) {
                'success'                  => $this->color->ok('DONE', ['bold' => 1]),
                'released_after_exception' => $this->color->warn('FAIL', ['bold' => 1]),
                default                    => $this->color->error('FAIL', ['bold' => 1]),
            };
        }

        $this->justify($first, $second);
    }

    /**
     * Affiche l'état du worker au format JSON.
     *
     * @param mixed $status
     */
    protected function writeOutputAsJson(Job $job, $status, ?Throwable $exception = null): void
    {
        $log = array_filter([
            'level'      => $status === 'starting' || $status === 'success' ? 'info' : 'warning',
            'id'         => $job->getJobId(),
            'uuid'       => $job->uuid(),
            'connection' => $job->getConnectionName(),
            'queue'      => $job->getQueue(),
            'job'        => $job->resolveName(),
            'status'     => $status,
            'result'     => match (true) {
                $job->isDeleted()  => 'deleted',
                $job->isReleased() => 'released',
                $job->hasFailed()  => 'failed',
                default            => '',
            },
            'attempts'  => $job->attempts(),
            'exception' => $exception ? $exception::class : '',
            'message'   => $exception?->getMessage(),
            'timestamp' => $this->now()->format('Y-m-d\TH:i:s.uP'),
        ]);

        if ($status === 'starting') {
            $this->latestStartedAt = microtime(true);
        } else {
            $log['duration'] = round(microtime(true) - $this->latestStartedAt, 6);
        }

        $this->json($log);
    }

    /**
     * Retourne la date et l'heure courantes.
     */
    protected function now(): Date
    {
        $queueTimezone = config('queue.output_timezone');

        if ($queueTimezone && $queueTimezone !== config('app.timezone')) {
            return Date::now()->setTimezone($queueTimezone);
        }

        return Date::now();
    }

    /**
     * Enregistre un événement de job échoué.
     */
    protected function logFailedJob(QueueEvent $event): void
    {
        service('queueFailer')->log(
            $event->connection,
            $event->job->getQueue(),
            $event->job->getRawBody(),
            $event->exception,
        );
    }

    /**
     * Retourne le nom de file à traiter par le worker.
     */
    protected function getQueue(string $connection): string
    {
        return $this->option('queue') ?: config(
            "queue.connections.{$connection}.queue",
            'default',
        );
    }

    /**
     * Indique si l'application est en maintenance (et si le worker doit s'arrêter).
     */
    protected function downForMaintenance(): bool
    {
        return $this->option('force')
            ? false
            : config('app.maintenance.enable', false); // $this->laravel->isDownForMaintenance();
    }

    /**
     * Indique si la sortie du worker doit être en JSON.
     */
    protected function outputUsingJson(): bool
    {
        return filter_var($this->option('json'), FILTER_VALIDATE_BOOLEAN) === true;
    }

    /**
     * Réinitialise les variables statiques.
     */
    public static function flushState(): void
    {
        static::$hasRegisteredListeners = false;
    }

    /**
     * Indique si la sortie console est silencieuse (non CLI ou mode suppress).
     */
    protected function isSilent(): bool
    {
        return $this->suppress || ! is_cli();
    }

    /**
     * Indique si le terminal courant prend en charge `stty`.
     *
     * @internal
     */
    protected static function terminalHasSttyAvailable(): bool
    {
        if (null !== self::$stty) {
            return self::$stty;
        }

        // Pas de vérification si shell_exec est désactivé
        if (! \function_exists('shell_exec')) {
            return false;
        }

        return self::$stty = (bool) @shell_exec('stty 2> ' . ('\\' === \DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null'));
    }
}
