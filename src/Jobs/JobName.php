<?php

namespace BlitzPHP\Queue\Jobs;

use BlitzPHP\Utilities\String\Text;

class JobName
{
    /**
     * Parse the given job name into a class / method array.
     */
    public static function parse(string $job): array
    {
        return Text::parseCallback($job, 'fire');
    }

    /**
     * Get the resolved name of the queued job class.
     */
    public static function resolve(string $name, array $payload): string
    {
        if (! empty($payload['displayName'])) {
            return $payload['displayName'];
        }

        return $name;
    }

    /**
     * Get the class name for queued job class.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function resolveClassName(string $name, array $payload): string
    {
        if (is_string($payload['data']['commandName'] ?? null)) {
            return $payload['data']['commandName'];
        }

        return $name;
    }
}
