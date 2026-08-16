<?php

namespace App\Mvp\Documents\Domain\Ports\Outbound;

/**
 * Porta secondaria verso la pubblicazione di eventi di dominio (Observer):
 * i casi d'uso pubblicano un fatto avvenuto senza sapere chi reagisce ne'
 * come (audit, metriche, in futuro notifiche). Nessun riferimento al bus di
 * eventi concreto. Non condivisa con Communications: gli eventi sono
 * specifici del dominio, come le porte di persistenza (vedi ADR 0010).
 */
interface DocumentEventDispatcherPort
{
    public function dispatch(object $event): void;
}
