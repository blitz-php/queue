<?php
namespace BlitzPHP\Queue\Failed;

/**
 * Contrat permettant de compter les jobs échoués, éventuellement par connexion et file.
 */
interface CountableFailedJobProvider
{
    /**
     * Compte les jobs échoués.
     */
    public function count(?string $connection = null, ?string $queue = null): int;
}