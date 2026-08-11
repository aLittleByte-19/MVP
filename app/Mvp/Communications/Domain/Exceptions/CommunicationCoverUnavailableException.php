<?php

namespace App\Mvp\Communications\Domain\Exceptions;

/**
 * La comunicazione non ha una copertina, oppure il file non e' presente
 * sullo storage. Eccezione di dominio pura: l'adapter primario la traduce
 * in un 404.
 */
class CommunicationCoverUnavailableException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Copertina non disponibile per questa comunicazione.');
    }
}
