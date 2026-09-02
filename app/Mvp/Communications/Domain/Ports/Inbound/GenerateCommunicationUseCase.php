<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\Commands\GenerateCommunicationCommand;

/**
 * Porta primaria: crea la bozza (stato draft/pending) e la registra in
 * audit. Non avvia la pipeline (vedi StartCommunicationWorkflowUseCase):
 * due decisioni distinte, invocate in sequenza dallo stesso adapter HTTP.
 */
interface GenerateCommunicationUseCase
{
    public function generate(GenerateCommunicationCommand $command): int;
}
