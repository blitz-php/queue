<?php

namespace BlitzPHP\Queue\Providers;

use BlitzPHP\Container\AbstractProvider;
use BlitzPHP\Contracts\Queue\Factory;
use BlitzPHP\Contracts\Queue\Monitor;
use BlitzPHP\Queue\Manager;
use BlitzPHP\Queue\Worker;

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
