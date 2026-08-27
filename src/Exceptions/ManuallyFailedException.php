<?php

/**
 * This file is part of BlitzPHP Queue.
 *
 * (c) 2026 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Queue\Exceptions;

use RuntimeException;

/**
 * Exception levée lorsqu'un job est marqué en échec manuellement (`fail()`).
 */
class ManuallyFailedException extends RuntimeException
{
}
