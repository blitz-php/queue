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

/**
 * Contrat permettant de compter les jobs échoués, éventuellement par connexion et file.
 */
interface CountableFailedJobProvider
{
    /**
     * Compte les jobs échoués.
     */
    public function count(?string $connection = null, ?string $queue = null): int;
}
