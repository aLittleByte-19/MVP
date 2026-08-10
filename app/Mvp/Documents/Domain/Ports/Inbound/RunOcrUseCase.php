<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

/**
 * Porta primaria: esegue l'OCR di un documento originale (task "textract.ocr").
 * Adapter primario: DocumentWorkflowTaskHandler, guidato da Step Functions.
 */
interface RunOcrUseCase
{
    /**
     * @return array{skipped: bool, jobId: ?string, confidenceAvg: ?float}
     */
    public function run(int $documentId, ?string $bucket, ?string $key): array;
}
