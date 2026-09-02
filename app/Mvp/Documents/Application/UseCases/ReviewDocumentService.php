<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Events\SubDocumentExtractedDataCorrected;
use App\Mvp\Documents\Domain\Events\SubDocumentManuallyValidated;
use App\Mvp\Documents\Domain\Exceptions\DocumentNotAuthorizedException;
use App\Mvp\Documents\Domain\Exceptions\MissingExtractedDataException;
use App\Mvp\Documents\Domain\Ports\Inbound\ReviewDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\ValueObjects\ExtractedDataChanges;
use App\Mvp\Support\Identity\Actor;

/**
 * Difesa in profondita' sul tenant su entrambi i metodi (stesso principio
 * del docblock di CommunicationDraftService).
 */
class ReviewDocumentService implements ReviewDocumentUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentEventDispatcherPort $events,
    ) {}

    public function updateExtractedData(int $subDocumentId, array $fieldUpdates, bool $markAsValidated, Actor $actor): string
    {
        $this->assertOwnership($subDocumentId, $actor);

        if ($fieldUpdates !== [] || ! $this->documents->subDocumentHasExtractedData($subDocumentId)) {
            $this->documents->saveExtractedData($subDocumentId, ExtractedDataChanges::fromRawFields($fieldUpdates));
        }

        $subDocument = $this->documents->findSubDocument($subDocumentId);

        if ($markAsValidated) {
            $subDocument->markManuallyValidated();
        } elseif ($subDocument->reviewStatus() !== ReviewStatus::ManuallyValidated) {
            // Una correzione riporta in revisione cio' che aveva validato il
            // sistema, non cio' che una persona ha gia' confermato —
            // declassare quei dati la costringerebbe a confermarli due volte.
            $subDocument->markNeedsReview();
        }

        $this->documents->saveSubDocument($subDocument);

        $reviewStatus = $subDocument->reviewStatus();
        $this->events->dispatch(new SubDocumentExtractedDataCorrected($subDocumentId, $actor, array_keys($fieldUpdates), $reviewStatus->value));

        return $reviewStatus->value;
    }

    public function markReviewed(int $subDocumentId, Actor $actor): void
    {
        $this->assertOwnership($subDocumentId, $actor);

        if (! $this->documents->subDocumentHasExtractedData($subDocumentId)) {
            throw new MissingExtractedDataException;
        }

        $subDocument = $this->documents->findSubDocument($subDocumentId);
        $subDocument->markManuallyValidated();
        $this->documents->saveSubDocument($subDocument);

        $this->events->dispatch(new SubDocumentManuallyValidated($subDocumentId, $actor));
    }

    private function assertOwnership(int $subDocumentId, Actor $actor): void
    {
        $subDocument = $this->documents->findSubDocument($subDocumentId);
        $original = $this->documents->findOriginalDocument($subDocument->originalDocumentId);

        if ($original->tenantId !== $actor->tenantId) {
            throw new DocumentNotAuthorizedException;
        }
    }
}
