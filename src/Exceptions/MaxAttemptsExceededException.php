<?php

namespace BlitzPHP\Queue\Exceptions;

use BlitzPHP\Contracts\Queue\Job;
use RuntimeException;

/**
 * Exception levée lorsqu'un job a épuisé son nombre maximal de tentatives.
 */
class MaxAttemptsExceededException extends RuntimeException
{
    /**
     * Instance du job concerné.
     */
    public ?Job $job = null;

    /**
     * Crée une instance d'exception liée au job.
     */
    public static function forJob(Job $job): static
    {
        return tap(new static($job->resolveName().' has been attempted too many times.'), function ($e) use ($job) {
            $e->job = $job;
        });
    }
}
