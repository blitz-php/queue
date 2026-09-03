<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Config;

use BlitzPHP\Container\Services as BaseServices;
use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Queue\DTO\Config;
use BlitzPHP\Queue\Events\QueueEventManager;
use BlitzPHP\Queue\Failed\DatabaseFailedJobProvider;
use BlitzPHP\Queue\Failed\DatabaseUuidFailedJobProvider;
use BlitzPHP\Queue\Failed\FailedJobProviderInterface;
use BlitzPHP\Queue\Failed\FileFailedJobProvider;
use BlitzPHP\Queue\Failed\NullFailedJobProvider;
use BlitzPHP\Queue\Manager;
use BlitzPHP\Queue\Worker;

/**
 * Fabrique des services liés à la file d'attente (gestionnaire, worker, jobs échoués).
 */
class Services extends BaseServices
{
    /**
     * Gestionnaire de files d'attente.
     */
    public static function queue(array $config = [], bool $shared = true): Manager
    {
        if (true === $shared && isset(static::$instances[Manager::class])) {
            return static::$instances[Manager::class];
        }

        $config = $config === [] ? config('queue', []) : $config;

        return static::$instances[Manager::class] = new Manager(
            static::container(),
            Config::fromArray($config),
        );
    }

    /**
     * Worker de file d'attente.
     */
    public static function worker(bool $shared = true): Worker
    {
        if (true === $shared && isset(static::$instances[Worker::class])) {
            return static::$instances[Worker::class];
        }

        $isDownForMaintenance = fn () => (bool) static::config()->get('app.maintenance.enable', false);

        $resetScope = function () {
            $logger = static::logger();

            if (method_exists($logger, 'flushSharedContext')) {
                $logger->flushSharedContext();
            }

            if (method_exists($logger, 'withoutContext')) {
                $logger->withoutContext();
            }

            $db = static::database();

            if (method_exists($db, 'getConnections')) {
                foreach ($db->getConnections() as $connection) {
                    // $connection->resetTotalQueryDuration();
                    // $connection->allowQueryDurationHandlersToRunAgain();
                }
            }

            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
        };

        return static::$instances[Worker::class] = new Worker(
            static::queue(),
            static::singleton(QueueEventManager::class),
            $isDownForMaintenance,
            $resetScope,
        );
    }

    /**
     * Fournisseur de jobs échoués selon `queue.failed.driver`.
     */
    public static function queueFailer(array $config = [], bool $shared = true): FailedJobProviderInterface
    {
        if (true === $shared && isset(static::$instances[FailedJobProviderInterface::class])) {
            return static::$instances[FailedJobProviderInterface::class];
        }

        $config = $config === [] ? static::config()->get('queue.failed', []) : $config;
        $driver = $config['driver'] ?? 'null';

        return static::$instances[FailedJobProviderInterface::class] = match ($driver) {
            'database' => new DatabaseFailedJobProvider(
                static::singleton(ConnectionResolverInterface::class),
                $config['database'] ?? 'default',
                $config['table'] ?? 'queue_failed_jobs',
            ),
            'database-uuids' => new DatabaseUuidFailedJobProvider(
                static::singleton(ConnectionResolverInterface::class),
                $config['database'] ?? 'default',
                $config['table'] ?? 'queue_failed_jobs',
            ),
            'file' => new FileFailedJobProvider(
                $config['path'] ?? storage_path('logs/failed_jobs.json'),
                $config['limit'] ?? 100,
            ),
            default => new NullFailedJobProvider(),
        };
    }
}
