<?php

namespace App\Mvp\Communications\Domain\Exceptions;

/**
 * Invariante di CommunicationDraftBuilder: la copertina usa l'image_prompt
 * scritto dal modello testuale nello stesso passo che genera titolo e corpo,
 * quindi non puo' essere generata prima del testo (vedi ADR 0010).
 */
class CoverPrecedesTextException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Impossibile generare la copertina prima del testo: image_prompt non e\' ancora disponibile.');
    }
}
