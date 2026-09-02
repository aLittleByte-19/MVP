<?php

namespace App\Mvp\Documents\Domain\Exceptions;

/**
 * Il sotto-documento o l'originale non appartiene al tenant dell'attore.
 * L'adapter HTTP la traduce in 403.
 */
class DocumentNotAuthorizedException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Documento non autorizzato per il tenant corrente.');
    }
}
