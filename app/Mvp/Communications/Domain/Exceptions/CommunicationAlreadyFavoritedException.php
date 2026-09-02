<?php

namespace App\Mvp\Communications\Domain\Exceptions;

class CommunicationAlreadyFavoritedException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('La generazione è già contrassegnata come preferita.');
    }
}
