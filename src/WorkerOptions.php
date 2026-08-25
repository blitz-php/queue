<?php

namespace BlitzPHP\Queue;

class WorkerOptions
{
    /**
     * Create a new worker options instance.
     *
     * @param  string  $name The name of the worker.
     * @param  int|int[]  $backoff The number of seconds to wait before retrying a job that encountered an uncaught exception.
     * @param  int  $memory The maximum amount of RAM the worker may consume.
     * @param  int  $timeout The maximum number of seconds a child worker may run.
     * @param  int  $sleep The number of seconds to wait in between polling the queue.
     * @param  int  $maxTries The maximum number of times a job may be attempted.
     * @param  bool  $force Indicates if the worker should run in maintenance mode.
     * @param  bool  $stopWhenEmpty Indicates if the worker should stop when the queue is empty.
     * @param  int  $maxJobs The maximum number of jobs to run.
     * @param  int  $maxTime The maximum number of seconds a worker may live.
     * @param  int  $rest The number of seconds to rest between jobs.
     */
	public function __construct(
        public string $name = 'default',
        public array|int $backoff = 0,
        public int $memory = 128,
        public int $timeout = 60,
        public int $sleep = 3,
        public int $maxTries = 1,
        public bool $force = false,
        public bool $stopWhenEmpty = false,
        public int $maxJobs = 0,
        public int $maxTime = 0,
        public $rest = 0,
    ) {
	}
}
