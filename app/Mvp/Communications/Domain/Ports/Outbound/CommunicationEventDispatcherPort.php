<?php

namespace App\Mvp\Communications\Domain\Ports\Outbound;

/**
 * Porta secondaria verso la pubblicazione di eventi di dominio (Observer):
 * i casi d'uso pubblicano un fatto avvenuto (testo generato, copertina
 * degradata, bozza approvata, ...) senza sapere chi reagisce ne' come
 * (audit, metriche, in futuro notifiche). Nessun riferimento al bus di
 * eventi concreto (vedi ADR 0010).
 */
interface CommunicationEventDispatcherPort
{
    public function dispatch(object $event): void;
}
