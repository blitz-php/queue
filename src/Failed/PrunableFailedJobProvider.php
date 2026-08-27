<?php
namespace BlitzPHP\Queue\Failed;

use DateTimeInterface;

/**
 * Contrat de purge des jobs échoués antérieurs à une date donnée.
 */
interface PrunableFailedJobProvider
{
    /**
     * Purge les entrées antérieures à la date donnée.
     */
    public function prune(DateTimeInterface $before): int;
}