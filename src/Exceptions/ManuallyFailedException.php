<?php

namespace BlitzPHP\Queue\Exceptions;

use RuntimeException;

/**
 * Exception levée lorsqu'un job est marqué en échec manuellement (`fail()`).
 */
class ManuallyFailedException extends RuntimeException
{
    //
}
