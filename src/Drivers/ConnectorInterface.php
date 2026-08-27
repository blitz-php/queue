<?php

namespace BlitzPHP\Queue\Drivers;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Queue\Queue;

/**
 * Contrat des pilotes de file : établit une connexion à partir de la configuration.
 */
interface ConnectorInterface
{
    /**
     * Établit une connexion de file d'attente.
     */
    public static function connect(ContainerInterface $container, array $config): Queue;
}
