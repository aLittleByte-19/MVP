<?php

namespace App\Mvp\Documents\Adapters\Outbound\Ai;

use App\Mvp\Ai\BedrockService;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;

/**
 * Adapter secondario: implementa {@see DocumentAiGatewayPort} sopra
 * {@see BedrockService}, il client Bedrock condiviso (usato anche dal
 * dominio Communications per la generazione testo/immagine). Questo adapter
 * espone solo le due operazioni di dominio Documents: split ed estrazione.
 */
class BedrockDocumentAiAdapter implements DocumentAiGatewayPort
{
    public function __construct(private readonly BedrockService $bedrock) {}

    public function splitDocument(string $ocrText, int $pageCount, string $boundaryNonce): array
    {
        return $this->bedrock->splitDocument($ocrText, $pageCount, $boundaryNonce);
    }

    public function extractFields(string $ocrText): array
    {
        return $this->bedrock->extractFields($ocrText);
    }
}
