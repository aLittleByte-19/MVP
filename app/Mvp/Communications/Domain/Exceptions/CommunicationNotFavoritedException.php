<?php

namespace App\Mvp\Communications\Domain\Exceptions;

class CommunicationNotFavoritedException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('La generazione non è contrassegnata come preferita.');
    }
}
