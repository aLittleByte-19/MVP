<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Exceptions\DocumentPreviewUnavailableException;
use App\Mvp\Documents\Domain\Ports\Inbound\PreviewDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Documents\Domain\ValueObjects\PreviewableDocument;

class PreviewDocumentService implements PreviewDocumentUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentStoragePort $storage,
    ) {}

    public function preview(int $subDocumentId): PreviewableDocument
    {
        $subDocument = $this->documents->findSubDocument($subDocumentId);

        if (! $this->storage->exists($subDocument->filePath)) {
            throw new DocumentPreviewUnavailableException;
        }

        return new PreviewableDocument($this->storage->read($subDocument->filePath), $subDocument->originalFilename);
    }
}
