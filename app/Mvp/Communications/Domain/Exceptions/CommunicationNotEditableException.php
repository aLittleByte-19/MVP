<?php

namespace App\Mvp\Communications\Domain\Exceptions;

class CommunicationNotEditableException extends \DomainException
{
    public function __construct()
    {
        parent::__construct("Una bozza scartata non e' modificabile.");
    }
}
