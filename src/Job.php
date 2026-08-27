<?php

namespace BlitzPHP\Queue;

use BlitzPHP\Queue\Traits\Dispatchable;
use BlitzPHP\Queue\Traits\InteractsWithQueue;
use BlitzPHP\Queue\Traits\SerializesModels;

abstract class Job
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    protected int $maxTries = 3;
    
    protected int $backoff = 60;

    protected string $queue = '';

    public function maxTries(): int 
    {
        return $this->maxTries;
    }

    public function backoff(): int 
    {
        return $this->backoff;
    }

    public function queue(): string 
    {
        return $this->queue;
    }
}
