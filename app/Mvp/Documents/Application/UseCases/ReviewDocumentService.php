<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Events\SubDocumentExtractedDataCorrected;
use App\Mvp\Documents\Domain\Events\SubDocumentManuallyValidated;
use App\Mvp\Documents\Domain\Exceptions\MissingExtractedDataException;
use App\Mvp\Documents\Domain\Ports\Inbound\ReviewDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\ValueObjects\ExtractedDataChanges;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentChanges;
use App\Mvp\Support\Identity\Actor;

class ReviewDocumentService implements ReviewDocumentUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentEventDispatcherPort $events,
    ) {}

    public function updateExtractedData(int $subDocumentId, array $fieldUpdates, bool $markAsValidated, ?Actor $actor): string
    {
        if ($fieldUpdates !== [] || ! $this->documents->subDocumentHasExtractedData($subDocumentId)) {
            $this->documents->saveExtractedData($subDocumentId, ExtractedDataChanges::fromRawFields($fieldUpdates));
        }

        $reviewStatus = $markAsValidated ? ReviewStatus::ManuallyValidated : ReviewStatus::NeedsReview;

        $this->documents->updateSubDocument($subDocumentId, SubDocumentChanges::none()
            ->withReviewStatus($reviewStatus)
            ->withErrorMessage(null));

        $this->events->dispatch(new SubDocumentExtractedDataCorrected($subDocumentId, $actor, array_keys($fieldUpdates), $reviewStatus->value));

        return $reviewStatus->value;
    }

    public function markReviewed(int $subDocumentId, ?Actor $actor): void
    {
        if (! $this->documents->subDocumentHasExtractedData($subDocumentId)) {
            throw new MissingExtractedDataException;
        }

        $this->documents->updateSubDocument($subDocumentId, SubDocumentChanges::none()
            ->withReviewStatus(ReviewStatus::ManuallyValidated)
            ->withErrorMessage(null));

        $this->events->dispatch(new SubDocumentManuallyValidated($subDocumentId, $actor));
    }
}
