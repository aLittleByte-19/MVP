<?php

namespace App\Mvp\Communications\Domain\Exceptions;

/**
 * La rigenerazione richiede che la generazione precedente sia conclusa
 * (completata o fallita): distinta da CommunicationAlreadyDiscardedException
 * perche' l'adapter primario le traduce in status HTTP diversi (409 qui,
 * 422 la').
 */
class CommunicationRegenerationUnavailableException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('La rigenerazione e disponibile solo a generazione conclusa.');
    }
}
