<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\Commands\GenerateCommunicationCommand;

/**
 * Porta primaria: crea la bozza (stato draft/pending) e la registra in audit.
 * Non avvia la pipeline (vedi StartCommunicationWorkflowUseCase): due
 * decisioni distinte invocate in sequenza dallo stesso adapter HTTP, stessa
 * scomposizione di Documents (vedi ADR 0010).
 */
interface GenerateCommunicationUseCase
{
    public function generate(GenerateCommunicationCommand $command): int;
}
