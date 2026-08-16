<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Documents\Domain\Entities\OriginalDocument;

/**
 * Porta primaria: estrae ed elabora i campi di un singolo sotto-documento
 * (destinatario) gia' creato da uno split. Separata da ProcessDocumentUseCase
 * (che orchestra lo split e chiama questa porta per ciascun destinatario):
 * prima erano la stessa classe, unite solo perche' process() invocava questa
 * logica internamente per ogni segmento (vedi ADR 0010).
 */
interface ExtractSubDocumentFieldsUseCase
{
    public function extractAndSaveFields(int $subDocumentId): void;

    /**
     * Variante che riusa un OriginalDocument gia' risolto dal chiamante, per
     * evitare una query duplicata quando invocata da ProcessDocumentService
     * una volta per destinatario appena creato.
     */
    public function extractAndSaveFieldsWithContext(int $subDocumentId, OriginalDocument $original): void;
}
