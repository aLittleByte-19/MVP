<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Events\SubDocumentDeleted;
use App\Mvp\Documents\Domain\Ports\Inbound\DeleteDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Support\Identity\Actor;
use Psr\Log\LoggerInterface;

class DeleteDocumentService implements DeleteDocumentUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentStoragePort $storage,
        private readonly DocumentEventDispatcherPort $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function delete(int $subDocumentId, ?Actor $actor): void
    {
        $subDocument = $this->documents->findSubDocument($subDocumentId);
        $originalDocumentId = $subDocument->originalDocumentId;

        $this->documents->deleteSubDocument($subDocumentId);
        $this->deleteStoragePath($subDocument->filePath);

        $this->events->dispatch(new SubDocumentDeleted($subDocumentId, $originalDocumentId, $actor));

        if (! $this->documents->originalDocumentHasRemainingSubDocuments($originalDocumentId)) {
            $original = $this->documents->findOriginalDocument($originalDocumentId);
            $this->documents->deleteOriginalDocumentWithWorkflowTasks($originalDocumentId);
            $this->deleteStoragePath($original->filePath);
        }
    }

    /**
     * Il record e' gia' rimosso dal DB a questo punto: un guasto di storage
     * (es. rete verso S3) non deve far fallire un'eliminazione che dal punto
     * di vista dell'utente e' gia' avvenuta — resta solo un file orfano,
     * ripulibile in un secondo momento, non un riferimento pendente (vedi
     * ADR 0010, stesso trattamento di ProcessDocumentService::deleteStoragePaths()).
     */
    private function deleteStoragePath(string $path): void
    {
        try {
            $this->storage->delete($path);
        } catch (\Throwable $e) {
            $this->logger->warning('DeleteDocumentService: storage cleanup failed', ['path' => $path, 'message' => $e->getMessage()]);
        }
    }
}
