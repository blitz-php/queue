<?php

namespace BlitzPHP\Queue\Exceptions;

use InvalidArgumentException;

/**
 * Exception levée lorsque le payload d'un job ne peut pas être encodé en JSON.
 */
class InvalidPayloadException extends InvalidArgumentException
{
    /**
     * Valeur dont le décodage / l'encodage a échoué.
     */
    public mixed $value;

    /**
     * Crée une nouvelle instance d'exception.
     */
    public function __construct(?string $message = null, mixed $value = null)
    {
        parent::__construct($message ?: json_last_error());

        $this->value = $value;
    }
}
