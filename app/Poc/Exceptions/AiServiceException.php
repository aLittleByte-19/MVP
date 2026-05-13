<?php

namespace App\Poc\Exceptions;

use Exception;
use Throwable;

/**
 * Exception thrown when there is an error with the AI service.
 */
class AiServiceException extends Exception
{
    /**
     * Create a new exception instance.
     *
     * @param  string  $message
     * @param  int  $code
     * @param  \Throwable|null  $previous
     * @return void
     */
    public function __construct(string $message = "Servizio AI non disponibile.", int $code = 502, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
