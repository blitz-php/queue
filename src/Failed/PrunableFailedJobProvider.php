<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Failed;

use DateTimeInterface;

/**
 * Contrat de purge des jobs échoués antérieurs à une date donnée.
 */
interface PrunableFailedJobProvider
{
    /**
     * Purge les entrées antérieures à la date donnée.
     */
    public function prune(DateTimeInterface $before): int;
}
