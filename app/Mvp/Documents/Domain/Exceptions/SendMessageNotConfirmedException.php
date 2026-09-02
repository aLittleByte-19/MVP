<?php

namespace App\Mvp\Documents\Domain\Exceptions;

/**
 * Il messaggio si scarica solo dopo che una persona ha confermato i dati
 * estratti.
 *
 * La validazione automatica dice che il testo era leggibile, non che il
 * documento sia della persona a cui verra' consegnato: la verifica umana e' il
 * passaggio che il download presuppone, e il vincolo sta qui e non solo nel
 * pannello perche' l'API si puo' chiamare anche senza passare di li'
 * (questione aperta 3 dell'ADR 0012).
 */
class SendMessageNotConfirmedException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Il messaggio si scarica solo dopo la conferma dei dati estratti.');
    }
}
