<?php

namespace BlitzPHP\Queue\Models;

use BlitzPHP\Contracts\Database\ConnectionInterface;
use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Database\Connection\BaseConnection;
use BlitzPHP\Database\Model;
use BlitzPHP\Traits\Support\InteractsWithTime;
use BlitzPHP\Utilities\Date;
use Throwable;

class JobModel extends Model
{
    use InteractsWithTime;

    protected string $dateFormat    = 'int';
    protected bool $allowCallbacks = false;

    /**
     * The expiration time of a job.
     */
    protected ?int $retryAfter = 60;

    /**
     * @param ConnectionInterface $db
     */
	public function __construct(array $config, protected ConnectionResolverInterface $resolver, ConnectionInterface $db)
	{
        assert($db instanceof BaseConnection);
        
        $this->table      = $config['table'];
        $this->retryAfter = $config['retry_after'] ?? 60;

        // Turn off the Strict Mode
        $db->transStrict(false);
        
        parent::__construct($resolver, $db);
	}

    /**
     * Get the size of the queue.
     */
    public function size(string $queue): int
    {
        return $this->builder()
            ->where('queue', $queue)
            ->count();
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(string $queue): int
    {
        return $this->builder()
            ->where('queue', $queue)
            ->where('available_at <=', $this->currentTime())
            ->whereNull('reserved_at')
            ->count();
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(string $queue): int
    {
        return $this->builder()
            ->where('queue', $$queue)
            ->where('available_at >', $this->currentTime())
            ->whereNull('reserved_at')
            ->count();
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(string $queue): int
    {
        return $this->builder()
            ->where('queue', $$queue)
            ->whereNotNull('reserved_at')
            ->count();
    }

    /**
     * Get the pending jobs for the given queue.
     */
    public function pendingJobs(string $queue): array
    {
        return $this->builder()
            ->where('queue', $queue)
            ->where('available_at <=', $this->currentTime())
            ->whereNull('reserved_at')
            ->all();
    }

    /**
     * Get the delayed jobs for the given queue.
     */
    public function delayedJobs(string $queue): array
    {
        return $this->builder()
            ->where('queue', $queue)
            ->where('available_at >', $this->currentTime())
            ->whereNull('reserved_at')
            ->all();
    }

    /**
     * Get the reserved jobs for the given queue.
     */
    public function reservedJobs(string $queue): array
    {
        return $this->builder()
            ->where('queue', $queue)
            ->whereNotNull('reserved_at')
            ->all();
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(string $queue): ?int
    {
        return $this->builder()
            ->where('queue', $queue)
            ->where('available_at <=', $this->currentTime())
            ->whereNull('reserved_at')
            ->sortAsc('available_at')
            ->value('available_at');
    }

    /**
     * Push a raw payload to the database with a given delay of (n) seconds.
     */
    public function pushToDatabase(array $data): mixed
    {
		$this->builder()->insert($data);

        return $this->db->lastId($this->table);
    }

    /**
     * Get the next available job for the queue.
     */
    public function getNextAvailableJob(string $queue): ?object
    {
        return $this->builder()
            // ->lock($this->getLockForPopping()) available only in blitz-php/database > 1.2
            ->where('queue', $queue)
            ->where(function ($query) {
                $this->isAvailable($query);
                $this->isReservedButExpired($query);
            })
            ->orderBy('id', 'asc')
            ->first();
    }

    /**
     * Delete a reserved job from the queue.
     *
     * @throws Throwable
     */
    public function deleteReserved(string $queue, string $id): void
    {
        $this->db->transaction(function () use ($id) {
            if ($this/*->lockForUpdate()*/->where('id', $id)->first()) {
                $this->where('id', $id)->delete();
            }
        });
    }

    
    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(string $queue): bool
    {
        $this->builder()->where('queue', $queue)->delete();

		return true;
    }

    /**
     * Modify the query to check for available jobs.
     */
    protected function isAvailable(BaseBuilder $query): void
    {
        $query->where(function ($query) {
            $query->whereNull('reserved_at')
                ->where('available_at <=', $this->currentTime());
        });
    }

    /**
     * Modify the query to check for jobs that are reserved but have expired.
     */
    protected function isReservedButExpired(BaseBuilder $query): void
    {
        $expiration = Date::now()->subSeconds($this->retryAfter)->getTimestamp();

        $query->orWhere(function ($query) use ($expiration) {
            $query->where('reserved_at <=', $expiration);
        });
    }
}
