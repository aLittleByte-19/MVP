<?php

namespace App\Mvp\Documents\Domain\Exceptions;

/**
 * Regola di dominio: un sotto-documento non puo' essere validato manualmente
 * senza dati estratti da correggere. Eccezione di dominio pura (non
 * un'eccezione HTTP): l'adapter primario la traduce nella risposta 422
 * appropriata al proprio protocollo.
 */
class MissingExtractedDataException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Correggi i dati estratti prima di validare manualmente il sotto-documento.');
    }
}
