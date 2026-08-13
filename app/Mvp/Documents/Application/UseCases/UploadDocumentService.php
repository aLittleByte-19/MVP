<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Commands\UploadDocumentCommand;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Ports\Inbound\UploadDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Documents\Domain\ValueObjects\NewOriginalDocument;
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

        $documentId = $this->documents->createOriginalDocument(new NewOriginalDocument(
            tenantId: $command->actor?->tenantId ?? 'mvp-local-tenant',
            createdBy: $command->actor?->id,
            filePath: $path,
            originalFilename: $safeName,
            manualDocumentType: $command->manualDocumentType,
            manualCompanyName: $command->manualCompanyName,
            manualReferenceMonth: $command->manualReferenceMonth,
            manualReferenceYear: $command->manualReferenceYear,
            processingStatus: ProcessingStatus::Pending,
        ));

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
