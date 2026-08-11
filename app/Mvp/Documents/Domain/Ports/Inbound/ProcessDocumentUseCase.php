<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

/**
 * Porta primaria: divide un documento originale nei suoi destinatari e
 * avvia, per ciascuno, l'estrazione campi (tramite ExtractSubDocumentFieldsUseCase).
 * Invocata dall'adapter di workflow (DocumentWorkflowTaskHandler, task
 * "bedrock.extract") — non da HTTP: e' l'esempio concreto di adapter
 * primario "guidato da workflow" invece che da una richiesta HTTP (vedi
 * ADR 0010).
 */
interface ProcessDocumentUseCase
{
    public function process(int $documentId): void;
}
