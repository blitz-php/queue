<?php

namespace BlitzPHP\Queue\Commands;

use BlitzPHP\Cache\Handlers\BaseHandler;
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

class Work extends Command
{
    use InteractsWithTime;
    
    /** @var string Groupe auquel appartient la commande */
    protected $group = 'Queue';

    /** @var string Nom de la commande */
    protected $name = 'queue:work';

    /** @var string Description de la commande */
    protected $description = 'Start processing jobs on the queue as a daemon';

    /** @var array Arguments de la commande */
    protected $arguments = [
        'connection' => 'The name of the queue connection to work',
    ];

    /** @var array Options de la commande */
    protected $options = [
        '--name'            => ['The name of the worker', 'default'],
        '--queue'           => ['The names of the queues to work'],
        '--daemon'          => ['Run the worker in daemon mode (Deprecated)'],
        '--once'            => ['Only process the next job on the queue'],
        '--stop-when-empty' => ['Stop when the queue is empty'],
        '--delay'           => ['The number of seconds to delay failed jobs (Deprecated)', 0],
        '--backoff'         => ['The number of seconds to wait before retrying a job that encountered an uncaught exception', 0],
        '--max-jobs'        => ['The number of jobs to process before stopping', 0],
        '--max-time'        => ['The maximum number of seconds the worker should run', 0],
        '--force'           => ['Force the worker to run even in maintenance mode'],
        '--memory'          => ['The memory limit in megabytes', 128],
        '--sleep'           => ['The number of seconds to sleep when no job is available', 3],
        '--rest'            => ['The number of seconds to rest between jobs', 0],
        '--timeout'         => ['The number of seconds a child process can run', 60],
        '--tries'           => ['The number of times to attempt a job before logging it failed', 1],
        '--json'            => ['Output the queue worker information as JSON'],
    ];

    /**
     * The queue worker instance.
     */
    protected Worker $worker;

    /**
     * The cache store implementation.
     */
    protected CacheInterface $cache;

    protected EventManagerInterface $events;


    /**
     * Holds the start time of the last processed job, if any.
     */
    protected ?float $latestStartedAt = null;

    /**
     * Indicates if the worker's event listeners have been registered.
     */
    private static bool $hasRegisteredListeners = false;

    private static ?bool $stty = null;

    /**
     * Create a new queue work command.
     */
    public function __construct(protected ContainerInterface $container, protected Console $app)
    {
        parent::__construct($app, $container->get(LoggerInterface::class));

        $this->worker = service('worker');
        $this->cache  = $container->get(CacheInterface::class);
        $this->events = $container->get(EventManagerInterface::class);

        BaseHandler::setReservedCharacters(str_replace(':', '', config('cache.reserved_characters')));
    }

    /**
     * Execute the console command.
     *
     * @return int|null
     */
    public function execute(array $params)
    {
        set_time_limit(0);

        if ($this->downForMaintenance() && $this->option('once')) {
            return $this->worker->sleep($this->option('sleep'));
        }

        // We'll listen to the processed and failed events so we can write information
        // to the console as jobs are processed, which will let the developer watch
        // which jobs are coming through a queue and be informed on its progress.
        $this->listenForEvents();

        $connection = $this->argument('connection') ?: config('queue.default');

        // We need to get the right queue for the connection which is set in the queue
        // configuration file for the application. We will pull it based on the set
        // connection being run for the queue operation currently being executed.
        $queue = $this->getQueue($connection);

        if (! $this->outputUsingJson() && static::terminalHasSttyAvailable()) {
            $this->info(
                sprintf('Processing jobs from the [%s] %s.', $queue, (new Stringable('queue'))->plural(explode(',', $queue)))
            );
        }

        return $this->runWorker(
            $connection, $queue
        );
    }

    /**
     * Run the worker instance.
     */
    protected function runWorker(string $connection, string $queue): ?int
    {
        return $this->worker
            ->setName($this->option('name'))
            ->setCache($this->cache)
            ->{$this->option('once') ? 'runNextJob' : 'daemon'}(
                $connection, $queue, $this->gatherWorkerOptions()
            );
    }

    /**
     * Gather all of the queue worker options as a single object.
     */
    protected function gatherWorkerOptions(): WorkerOptions
    {
        return new WorkerOptions(
            $this->option('name'),
            max($this->option('backoff'), $this->option('delay')),
            $this->option('memory'),
            $this->option('timeout'),
            $this->option('sleep'),
            $this->option('tries'),
            $this->option('force', false),
            $this->option('stop-when-empty', false),
            $this->option('max-jobs'),
            $this->option('max-time'),
            $this->option('rest'),
        );
    }

    /**
     * Listen for the queue events in order to update the console output.
     */
    protected function listenForEvents(): void
    {
        if (static::$hasRegisteredListeners) {
            return;
        }

        $this->events->on(QueueEventManager::JOB_PROCESSING, function(QueueEvent $event) {
            $this->writeOutput($event->job, 'starting');
        });
        
        $this->events->on(QueueEventManager::JOB_PROCESSED, function(QueueEvent $event) {
            $this->writeOutput($event->job, 'success');
        });

        $this->events->on(QueueEventManager::JOB_RELEASED_AFTER_EXCEPTION, function(QueueEvent $event) {
            $this->writeOutput($event->job, 'released_after_exception');
        });

        $this->events->on(QueueEventManager::JOB_FAILED, function(QueueEvent $event) {
            $this->writeOutput($event->job, 'failed', $event->exception);

            $this->logFailedJob($event);
        });

        static::$hasRegisteredListeners = true;
    }

    /**
     * Write the status output for the queue worker for JSON or TTY.
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
     * Write the status output for the queue worker.
     */
    protected function writeOutputForCli(Job $job, string $status): void
    {
        $isVerbose = $this->option('verbose');

        $first = sprintf('%s %s %s', 
            $this->color->comment($this->now()->format('Y-m-d H:i:s')),
            $job->resolveName(),
            ! $isVerbose ? '' : sprintf('%s %s', 
                $this->color->comment($job->getJobId()),
                $this->color->info($job->getConnectionName() . ' ' . $job->getQueue())
            )
        );

        if ($status == 'starting') {
            $this->latestStartedAt = microtime(true);

            $second = $this->color->warn('RUNNING', ['bold' => 1]);
        } else {
            $runTime = (microtime(true) - $this->latestStartedAt) * 1000;
            $runTime = (float) number_format($runTime, 2, '.', '');

            $memory = $isVerbose ? round(memory_get_usage(true) / 1024 / 1024, 1).'MB' : '';

            $second = $this->color->comment("{$runTime} ms".($memory ? " {$memory}" : '') . " ");
            $second .= match ($status) {
                'success' => $this->color->ok('DONE', ['bold' => 1]),
                'released_after_exception' => $this->color->warn('FAIL', ['bold' => 1]),
                default => $this->color->error('FAIL', ['bold' => 1]),
            };
        }
        
        $this->justify($first, $second);
    }

    /**
     * Write the status output for the queue worker in JSON format.
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
                $job->isDeleted() => 'deleted',
                $job->isReleased() => 'released',
                $job->hasFailed() => 'failed',
                default => '',
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
     * Get the current date / time.
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
     * Store a failed job event.
     */
    protected function logFailedJob(QueueEvent $event): void
    {
        service('queueFailer')->log(
            $event->connection,
            $event->job->getQueue(),
            $event->job->getRawBody(),
            $event->exception
        );
    }

    /**
     * Get the queue name for the worker.
     */
    protected function getQueue(string $connection): string
    {
        return $this->option('queue') ?: config(
            "queue.connections.{$connection}.queue", 'default'
        );
    }

    /**
     * Determine if the worker should run in maintenance mode.
     */
    protected function downForMaintenance(): false
    {
        return $this->option('force') 
            ? false 
            : config('app.maintenance.enable', false); // $this->laravel->isDownForMaintenance();
    }

    /**
     * Determine if the worker should output using JSON.
     */
    protected function outputUsingJson(): bool
    {
        return filter_var($this->option('json'), FILTER_VALIDATE_BOOLEAN) === true;
    }

    /**
     * Reset static variables.
     */
    public static function flushState(): void
    {
        static::$hasRegisteredListeners = false;
    }

    protected function isSilent(): bool
    {
        return $this->suppress || !is_cli();
    }

    /**
     * @internal
     */
    protected static function terminalHasSttyAvailable(): bool
    {
        if (null !== self::$stty) {
            return self::$stty;
        }

        // skip check if shell_exec function is disabled
        if (!\function_exists('shell_exec')) {
            return false;
        }

        return self::$stty = (bool) @shell_exec('stty 2> '.('\\' === \DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null'));
    }
}
