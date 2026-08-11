<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Documents\Domain\ValueObjects\DocumentProgressSnapshot;

/**
 * Porta primaria: lettura dello stato corrente per il polling SSE
 * dell'avanzamento di elaborazione di un documento.
 */
interface PollDocumentProgressUseCase
{
    /**
     * L'eccezione sollevata se il documento non esiste piu' e' quella
     * dell'adapter (stesso comportamento di findOriginalDocument()): il
     * chiamante che deve tradurla in una risposta HTTP la cattura in modo
     * generico, non per tipo concreto.
     */
    public function poll(int $documentId): DocumentProgressSnapshot;
}
