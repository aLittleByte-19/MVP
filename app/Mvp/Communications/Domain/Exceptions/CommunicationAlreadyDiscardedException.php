<?php

namespace App\Mvp\Communications\Domain\Exceptions;

class CommunicationAlreadyDiscardedException extends \DomainException
{
    public function __construct(string $message = 'La bozza risulta gia scartata.')
    {
        parent::__construct($message);
    }
}
