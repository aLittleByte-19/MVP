<?php

namespace App\Mvp\Documents\Domain\Exceptions;

/**
 * Il file del sotto-documento non e' presente sullo storage documenti.
 * Eccezione di dominio pura: l'adapter primario la traduce in un 404.
 */
class DocumentPreviewUnavailableException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Anteprima non disponibile: il file del sotto-documento non e\' presente sullo storage.');
    }
}
