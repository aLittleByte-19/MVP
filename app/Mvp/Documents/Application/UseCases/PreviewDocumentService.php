<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Exceptions\DocumentNotAuthorizedException;
use App\Mvp\Documents\Domain\Exceptions\DocumentPreviewUnavailableException;
use App\Mvp\Documents\Domain\Ports\Inbound\PreviewDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use App\Mvp\Documents\Domain\ValueObjects\PreviewableDocument;
use App\Mvp\Support\Identity\Actor;

class PreviewDocumentService implements PreviewDocumentUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentStoragePort $storage,
    ) {}

    public function preview(int $subDocumentId, Actor $actor): PreviewableDocument
    {
        $subDocument = $this->documents->findSubDocument($subDocumentId);
        $original = $this->documents->findOriginalDocument($subDocument->originalDocumentId);

        if ($original->tenantId !== $actor->tenantId) {
            throw new DocumentNotAuthorizedException;
        }

        if (! $this->storage->exists($subDocument->filePath)) {
            throw new DocumentPreviewUnavailableException;
        }

        return new PreviewableDocument($this->storage->read($subDocument->filePath), $subDocument->originalFilename);
    }

    public function previewOriginal(int $subDocumentId, Actor $actor): PreviewableDocument
    {
        $subDocument = $this->documents->findSubDocument($subDocumentId);
        $original = $this->documents->findOriginalDocument($subDocument->originalDocumentId);

        if ($original->tenantId !== $actor->tenantId) {
            throw new DocumentNotAuthorizedException;
        }

        if (! $this->storage->exists($original->filePath)) {
            throw new DocumentPreviewUnavailableException;
        }

        return new PreviewableDocument(
            $this->storage->read($original->filePath),
            $original->originalFilename ?? 'documento-originale.pdf',
        );
    }
}
