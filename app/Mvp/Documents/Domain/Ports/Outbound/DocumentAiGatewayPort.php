<?php

namespace App\Mvp\Documents\Domain\Ports\Outbound;

/**
 * Porta secondaria verso il classificatore AI: divisione di un documento in
 * destinatari ed estrazione dei campi per un singolo destinatario. Nessun
 * riferimento a Bedrock o all'SDK AWS: l'adapter (oggi basato su Bedrock)
 * decide come soddisfarla.
 */
interface DocumentAiGatewayPort
{
    /**
     * Divide il testo OCR di un documento in uno o più destinatari.
     *
     * @return array<int, array{employee_name: string, start_page: int, end_page: int}>
     */
    public function splitDocument(string $ocrText, int $pageCount, string $boundaryNonce): array;

    /**
     * Estrae i campi di un destinatario dal testo OCR del suo intervallo di pagine.
     *
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     */
    public function extractFields(string $ocrText): array;
}
