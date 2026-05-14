<?php

namespace App\Poc\Exceptions;

use Exception;
use Throwable;

class AiServiceException extends Exception
{
    public function __construct(string $message = 'Servizio AI non disponibile.', int $code = 502, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
