<?php

namespace App\Mvp\Documents\Domain\Ports\Outbound;

/**
 * Porta secondaria verso il servizio di OCR. Nessun riferimento a Textract o
 * all'SDK AWS: l'adapter (oggi basato su Textract) decide come soddisfarla.
 */
interface OcrGatewayPort
{
    /**
     * Avvia (o salta, se l'OCR e' disabilitato) il riconoscimento testo su un
     * oggetto S3 e ne restituisce il risultato.
     *
     * @return array{enabled: bool, jobId: ?string, text: ?string, pages: array<int, array{page: int, text: string, confidenceAvg: float|null}>, confidenceAvg: ?float}
     */
    public function detectText(string $bucket, string $key, string $idempotencyKey): array;
}
