<?php

namespace App\Mvp\Documents\Application\Support;

use App\Mvp\Documents\Domain\Entities\OriginalDocument;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;

/**
 * Costruisce il testo OCR di un intervallo di pagine, con i marcatori di
 * confine richiesti dal protocollo di prompt del gateway AI. Condiviso fra
 * ProcessDocumentService (split del documento) e ExtractSubDocumentFieldsService
 * (estrazione campi per destinatario): stessa lettura dell'aggregato OCR,
 * usata da entrambi con intervalli di pagine diversi.
 */
final class OcrRangeReader
{
    public function __construct(private readonly DocumentAiGatewayPort $ai) {}

    public function textForRange(OriginalDocument $original, int $startPage, int $endPage, string $boundaryNonce): string
    {
        $pages = $original->ocrPages;

        if ($pages !== []) {
            $parts = [];

            foreach ($pages as $page) {
                $number = (int) ($page['page'] ?? 0);

                if ($number < $startPage || $number > $endPage) {
                    continue;
                }

                $text = trim((string) ($page['text'] ?? ''));

                if ($text !== '') {
                    $parts[] = $this->ai->pageBoundaryMarker($number, $boundaryNonce)."\n".$text;
                }
            }

            if ($parts !== []) {
                return implode("\n\n", $parts);
            }
        }

        return trim((string) $original->ocrText);
    }

    public function nonce(): string
    {
        return bin2hex(random_bytes(8));
    }
}
