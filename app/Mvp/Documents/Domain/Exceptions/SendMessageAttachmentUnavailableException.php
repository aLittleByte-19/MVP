<?php

namespace App\Mvp\Documents\Domain\Exceptions;

/**
 * Il file del sotto-documento da accodare al messaggio non e' sullo storage.
 *
 * L'esportazione si ferma invece di consegnare un messaggio che dichiara un
 * allegato assente: meglio un errore che un PDF a meta' spedito a una persona.
 * Eccezione di dominio pura: l'adapter primario la traduce in una risposta.
 */
class SendMessageAttachmentUnavailableException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Il documento da allegare non e\' disponibile sullo storage: impossibile generare il messaggio.');
    }
}
