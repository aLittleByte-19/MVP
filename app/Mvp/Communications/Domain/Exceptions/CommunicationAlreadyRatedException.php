<?php

namespace App\Mvp\Communications\Domain\Exceptions;

class CommunicationAlreadyRatedException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('La valutazione è già stata registrata per questa bozza.');
    }
}
