<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Commands\UploadDocumentCommand;
use App\Mvp\Documents\Domain\Ports\Inbound\UploadDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Documents\Enums\ProcessingStatus;
use App\Mvp\Workflow\Support\WorkflowContext;

/**
 * Applicazione: salva il file caricato e crea il record del documento
 * originale. Non avvia la pipeline (vedi StartDocumentWorkflowUseCase): sono
 * due decisioni distinte, invocate in sequenza dallo stesso adapter HTTP.
 */
class UploadDocumentService implements UploadDocumentUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentStoragePort $storage,
        private readonly AuditLogger $audit,
        private readonly WorkflowContext $context,
    ) {}

    public function upload(UploadDocumentCommand $command): int
    {
        $this->context->bind($command->requestId, $command->correlationId, $command->actor?->tenantId);

        $path = $this->storage->storeFromLocalPath($command->absoluteSourcePath, 'originals');
        $safeName = preg_replace('/[^\w.\-]/u', '_', $command->originalFilename) ?: 'documento.pdf';

        $documentId = $this->documents->createOriginalDocument([
            'tenant_id' => $command->actor?->tenantId ?? 'mvp-local-tenant',
            'created_by' => $command->actor?->id,
            'file_path' => $path,
            'original_filename' => $safeName,
            'manual_document_type' => $command->manualDocumentType,
            'manual_company_name' => $command->manualCompanyName,
            'manual_reference_month' => $command->manualReferenceMonth,
            'manual_reference_year' => $command->manualReferenceYear,
            'processing_status' => ProcessingStatus::Pending,
        ]);

        $this->audit->record(
            'mvp-document-upload-accepted',
            $command->actor,
            'original_document',
            (string) $documentId,
            [
                'filename' => $safeName,
                'manual_metadata' => array_filter($command->manualMetadata(), static fn ($value) => $value !== null),
            ],
        );

        return $documentId;
    }
}
