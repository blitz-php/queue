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

use BlitzPHP\Queue\Config\Services;
use DateInterval;
use DateTimeInterface;

/**
 * Permet de dispatcher un job via des méthodes statiques (`dispatch`, `dispatchLater`, etc.).
 */
trait Dispatchable
{
    /**
     * Dispatch le job sur la queue
     */
    public static function dispatch(mixed ...$parameters): mixed
    {
        $job = new static(...$parameters);

        return Services::queue()->push($job, queue: $job->queue ?? null);
    }

    /**
     * Dispatch le job sur une queue spécifique
     */
    public static function dispatchOn(string $queue, mixed ...$parameters): mixed
    {
        $job = new static(...$parameters);

        return Services::queue()->pushOn($queue, $job);
    }

    /**
     * Dispatch le job avec délai
     */
    public static function dispatchLater(DateInterval|DateTimeInterface|int $delay, mixed ...$parameters): mixed
    {
        $job = new static(...$parameters);

        return Services::queue()->later($delay, $job, queue: $job->queue ?? null);
    }

    /**
     * Dispatch le job sur une queue spécifique avec délai
     */
    public static function dispatchLaterOn(string $queue, DateInterval|DateTimeInterface|int $delay, ...$parameters): mixed
    {
        $job = new static(...$parameters);

        return Services::queue()->laterOn($queue, $delay, $job);
    }

    /**
     * Dispatch le job immédiatement (synchrone)
     */
    public static function dispatchSync(mixed ...$parameters): void
    {
        $job = new static(...$parameters);

		Services::container()->call([$job, 'handle']);
    }
}
