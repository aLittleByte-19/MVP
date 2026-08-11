<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

use App\Mvp\Documents\Enums\ProcessingStatus;

/**
 * Dati per creare un nuovo OriginalDocument. A differenza di
 * OriginalDocumentChanges (aggiornamento parziale), qui tutti i campi sono
 * noti al momento della creazione: un costruttore semplice basta, non serve
 * costruzione incrementale (vedi ADR 0010).
 */
final class NewOriginalDocument
{
    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $createdBy,
        public readonly string $filePath,
        public readonly string $originalFilename,
        public readonly ?string $manualDocumentType,
        public readonly ?string $manualCompanyName,
        public readonly ?int $manualReferenceMonth,
        public readonly ?int $manualReferenceYear,
        public readonly ProcessingStatus $processingStatus,
    ) {}

    /**
     * @internal consumato solo dall'adapter di persistenza.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'created_by' => $this->createdBy,
            'file_path' => $this->filePath,
            'original_filename' => $this->originalFilename,
            'manual_document_type' => $this->manualDocumentType,
            'manual_company_name' => $this->manualCompanyName,
            'manual_reference_month' => $this->manualReferenceMonth,
            'manual_reference_year' => $this->manualReferenceYear,
            'processing_status' => $this->processingStatus,
        ];
    }
}
