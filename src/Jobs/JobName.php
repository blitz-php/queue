<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Jobs;

use BlitzPHP\Utilities\String\Text;

/**
 * Utilitaires de résolution du nom et de la classe d'un job enfilé.
 */
class JobName
{
    /**
     * Découpe le nom du job en tableau [classe, méthode].
     */
    public static function parse(string $job): array
    {
        return Text::parseCallback($job, 'fire');
    }

    /**
     * Retourne le nom résolu de la classe de job.
     */
    public static function resolve(string $name, array $payload): string
    {
        if (! empty($payload['displayName'])) {
            return $payload['displayName'];
        }

        return $name;
    }

    /**
     * Retourne le nom de classe du job enfilé.
     *
     * @param array<string, mixed> $payload
     */
    public static function resolveClassName(string $name, array $payload): string
    {
        if (is_string($payload['data']['commandName'] ?? null)) {
            return $payload['data']['commandName'];
        }

        return $name;
    }
}
