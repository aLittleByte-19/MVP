<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Ports\Inbound\PollDocumentProgressUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\ValueObjects\DocumentProgressSnapshot;

class PollDocumentProgressService implements PollDocumentProgressUseCase
{
    public function __construct(private readonly DocumentRepository $documents) {}

    public function poll(int $documentId): DocumentProgressSnapshot
    {
        $document = $this->documents->findOriginalDocument($documentId);

        return new DocumentProgressSnapshot(
            $document->processingStatus()->value,
            $document->errorMessage(),
            $this->documents->subDocumentIdsWithExtractedData($documentId),
        );
    }
}
