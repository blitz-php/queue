<?php

namespace BlitzPHP\Queue\Exceptions;

use BlitzPHP\Contracts\Queue\Job;

/**
 * Exception levée lorsqu'un job dépasse son délai d'exécution (timeout).
 */
class TimeoutExceededException extends MaxAttemptsExceededException
{
    /**
     * Crée une instance d'exception liée au job.
     */
    public static function forJob(Job $job): static
    {
        return tap(new static($job->resolveName().' has timed out.'), function ($e) use ($job) {
            $e->job = $job;
        });
    }
}
