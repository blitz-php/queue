<?php

namespace BlitzPHP\Queue\Jobs;

use BlitzPHP\Traits\Support\InteractsWithTime;

class DatabaseJobRecord
{
    use InteractsWithTime;

    /**
     * Create a new job record instance.
     *
     * @param  \stdClass  $record The underlying job record.
     */
    public function __construct(protected \stdClass $record)
    {
    }

    /**
     * Increment the number of times the job has been attempted.
     */
    public function increment(): int
    {
        $this->record->attempts++;

        return $this->record->attempts;
    }

    /**
     * Update the "reserved at" timestamp of the job.
     */
    public function touch(): int
    {
        $this->record->reserved_at = $this->currentTime();

        return $this->record->reserved_at;
    }

    /**
     * Dynamically access the underlying job information.
     */
    public function __get(string $key): mixed
    {
        return $this->record->{$key};
    }
}
