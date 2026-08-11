<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\ValueObjects\CommunicationProgressSnapshot;

/**
 * Porta primaria: lettura dello stato corrente per il polling SSE
 * dell'avanzamento di generazione di una comunicazione.
 */
interface PollCommunicationProgressUseCase
{
    /**
     * L'eccezione sollevata se la comunicazione non esiste piu' e' quella
     * dell'adapter (stesso comportamento di findCommunication()): il
     * chiamante che deve tradurla in una risposta HTTP la cattura in modo
     * generico, non per tipo concreto.
     */
    public function poll(int $communicationId): CommunicationProgressSnapshot;
}
