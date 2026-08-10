<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Ports\Inbound\DeleteDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Identity\MvpUser;

class DeleteDocumentService implements DeleteDocumentUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentStoragePort $storage,
        private readonly AuditLogger $audit,
    ) {}

    public function delete(int $subDocumentId, ?MvpUser $actor): void
    {
        $subDocument = $this->documents->findSubDocument($subDocumentId);
        $originalDocumentId = $subDocument->originalDocumentId;

        $this->documents->deleteSubDocument($subDocumentId);
        $this->storage->delete($subDocument->filePath);

        $this->audit->record(
            'mvp-sub-document-deleted',
            $actor,
            'sub_document',
            (string) $subDocumentId,
            ['original_document_id' => $originalDocumentId],
        );

        if (! $this->documents->originalDocumentHasRemainingSubDocuments($originalDocumentId)) {
            $original = $this->documents->findOriginalDocument($originalDocumentId);
            $this->documents->deleteOriginalDocumentWithWorkflowTasks($originalDocumentId);
            $this->storage->delete($original->filePath);
        }
    }
}
