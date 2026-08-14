<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

/**
 * Proiezione di dominio di un OriginalDocument: solo i campi che l'applicazione
 * legge per prendere decisioni (sovrascritture manuali, testo/pagine OCR,
 * stato di elaborazione). Nessun riferimento a Eloquent: e' il tipo che
 * DocumentRepository restituisce ai casi d'uso, non il model. Non e' un
 * doppione ridondante di OriginalDocument: e' il contratto di idratazione
 * condiviso fra OriginalDocument::fromRecord() consumato dall'adapter reale
 * (EloquentDocumentRepository) *e* dal fake usato nei test di dominio puro
 * (InMemoryDocumentRepository) — entrambi costruiscono lo stesso Record per
 * idratare l'entita', senza che il dominio sappia quale dei due lo chiama.
 */
final class OriginalDocumentRecord
{
    /**
     * @param  list<array{page: int, text: string, confidenceAvg: float|null}>  $ocrPages
     */
    public function __construct(
        public readonly int $id,
        public readonly string $tenantId,
        public readonly string $filePath,
        public readonly ?string $manualDocumentType,
        public readonly ?string $manualCompanyName,
        public readonly ?int $manualReferenceMonth,
        public readonly ?int $manualReferenceYear,
        public readonly string $processingStatus,
        public readonly ?string $ocrText,
        public readonly array $ocrPages,
        public readonly ?float $ocrConfidenceAvg,
        public readonly ?string $s3Bucket,
        public readonly ?string $s3Key,
        public readonly bool $workflowCompleted,
        public readonly ?string $workflowExecutionArn,
        public readonly ?string $errorMessage,
    ) {}

    public function hasManualUploadMetadata(): bool
    {
        return $this->manualDocumentType !== null
            || $this->manualCompanyName !== null
            || $this->manualReferenceMonth !== null
            || $this->manualReferenceYear !== null;
    }
}
