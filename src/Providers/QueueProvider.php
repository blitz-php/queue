<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Providers;

use BlitzPHP\Container\AbstractProvider;
use BlitzPHP\Contracts\Queue\Factory;
use BlitzPHP\Contracts\Queue\Monitor;
use BlitzPHP\Queue\Manager;
use BlitzPHP\Queue\Worker;

/**
 * Fournisseur de services : lie Factory, Monitor, Manager et Worker au conteneur.
 */
class QueueProvider extends AbstractProvider
{
    /**
     * {@inheritDoc}
     */
    public static function definitions(): array
    {
        return [
            Factory::class => static fn () => service('queue'),
            Monitor::class => static fn () => service('queue'),
            Manager::class => static fn () => service('queue'),
            Worker::class  => static fn () => service('worker'),
        ];
    }
}
