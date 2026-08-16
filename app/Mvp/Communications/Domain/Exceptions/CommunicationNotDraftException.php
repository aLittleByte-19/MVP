<?php

namespace App\Mvp\Communications\Domain\Exceptions;

class CommunicationNotDraftException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Solo le bozze in stato draft possono essere salvate nello storico.');
    }
}
