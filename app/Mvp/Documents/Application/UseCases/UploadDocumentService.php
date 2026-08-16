<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Commands\UploadDocumentCommand;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Events\DocumentUploadAccepted;
use App\Mvp\Documents\Domain\Ports\Inbound\UploadDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Documents\Domain\ValueObjects\NewOriginalDocument;

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
        private readonly DocumentEventDispatcherPort $events,
    ) {}

    public function upload(UploadDocumentCommand $command): int
    {
        $path = $this->storage->storeFromLocalPath($command->absoluteSourcePath, 'originals');
        $safeName = preg_replace('/[^\w.\-]/u', '_', $command->originalFilename) ?: 'documento.pdf';

        $documentId = $this->documents->createOriginalDocument(new NewOriginalDocument(
            tenantId: $command->actor->tenantId,
            createdBy: $command->actor->id,
            filePath: $path,
            originalFilename: $safeName,
            manualDocumentType: $command->manualDocumentType,
            manualCompanyName: $command->manualCompanyName,
            manualReferenceMonth: $command->manualReferenceMonth,
            manualReferenceYear: $command->manualReferenceYear,
            processingStatus: ProcessingStatus::Pending,
        ));

        $this->events->dispatch(new DocumentUploadAccepted(
            $documentId,
            $command->actor,
            $safeName,
            $command->manualMetadata(),
        ));

        return $documentId;
    }
}
