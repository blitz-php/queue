<?php

namespace BlitzPHP\Queue\Drivers;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Queue\Queue;

interface ConnectorInterface
{
    /**
     * Establish a queue connection.
     */
    public function connect(ContainerInterface $container, array $config): Queue;
}
