<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

/**
 * Porta primaria: divide un documento originale nei suoi destinatari ed
 * estrae i campi di ciascuno. Invocata dall'adapter di workflow
 * (DocumentWorkflowTaskHandler, task "bedrock.extract") — non da HTTP: e'
 * l'esempio concreto di adapter primario "guidato da workflow" invece che da
 * una richiesta HTTP (vedi ADR 0010).
 */
interface ProcessDocumentUseCase
{
    public function process(int $documentId): void;

    /**
     * Estrae ed elabora i campi di un singolo sotto-documento gia' creato.
     * Esposta a se stante (oltre che riusata internamente da `process()`)
     * perche' e' un punto di ingresso testabile in isolamento, senza dover
     * passare per split PDF e storage di un intero documento originale.
     */
    public function extractAndSaveFields(int $subDocumentId): void;
}
