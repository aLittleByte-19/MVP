<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Events\SubDocumentDeleted;
use App\Mvp\Documents\Domain\Ports\Inbound\DeleteDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Support\Identity\Actor;

class DeleteDocumentService implements DeleteDocumentUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentStoragePort $storage,
        private readonly DocumentEventDispatcherPort $events,
    ) {}

    public function delete(int $subDocumentId, ?Actor $actor): void
    {
        $subDocument = $this->documents->findSubDocument($subDocumentId);
        $originalDocumentId = $subDocument->originalDocumentId;

        $this->documents->deleteSubDocument($subDocumentId);
        $this->storage->delete($subDocument->filePath);

        $this->events->dispatch(new SubDocumentDeleted($subDocumentId, $originalDocumentId, $actor));

        if (! $this->documents->originalDocumentHasRemainingSubDocuments($originalDocumentId)) {
            $original = $this->documents->findOriginalDocument($originalDocumentId);
            $this->documents->deleteOriginalDocumentWithWorkflowTasks($originalDocumentId);
            $this->storage->delete($original->filePath);
        }
    }
}
