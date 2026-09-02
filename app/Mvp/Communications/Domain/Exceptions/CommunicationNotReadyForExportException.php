<?php

namespace App\Mvp\Communications\Domain\Exceptions;

class CommunicationNotReadyForExportException extends \DomainException
{
    public function __construct()
    {
        parent::__construct("La bozza non è ancora pronta per l'anteprima/esportazione.");
    }
}
