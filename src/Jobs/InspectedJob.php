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

use BlitzPHP\Utilities\Date;

/**
 * Vue en lecture seule d'un job (inspection des files en attente, retardées ou réservées).
 */
class InspectedJob
{
    /**
     * Crée une instance de job inspecté.
     *
     * @param string|null $uuid      Identifiant unique du job.
     * @param string|null $name      Nom d'affichage du job.
     * @param int         $attempts  Nombre de tentatives déjà effectuées.
     * @param Date|null   $createdAt Date et heure de création du job.
     */
    public function __construct(
        public readonly ?string $uuid,
        public readonly ?string $name,
        public readonly int $attempts,
        public readonly ?Date $createdAt,
    ) {
    }

    /**
     * Crée une instance à partir d'un payload JSON brut.
     *
     * @param string   $payload  Payload JSON brut du job.
     * @param int|null $attempts Nombre de tentatives déjà effectuées.
     */
    public static function fromPayload(string $payload, ?int $attempts = null): static
    {
        $decoded = json_decode($payload, true);

        return new static(
            uuid: $decoded['uuid'] ?? null,
            name: $decoded['displayName'] ?? null,
            attempts: $attempts ?? $decoded['attempts'] ?? 0,
            createdAt: isset($decoded['createdAt']) ? Date::createFromTimestamp($decoded['createdAt']) : null,
        );
    }
}
