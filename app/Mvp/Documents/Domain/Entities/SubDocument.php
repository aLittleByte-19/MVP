<?php

namespace App\Mvp\Documents\Domain\Entities;

use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Enums\SendStatus;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentChanges;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentRecord;

/**
 * Entità (non solo VO): a differenza di SubDocumentRecord (proiezione di
 * sola lettura), governa le proprie transizioni di reviewStatus e sendStatus
 * invece di lasciarle scrivere a mano da ogni caso d'uso (ADR 0010).
 *
 * Gli override di invio restano fuori: quel flusso (SendMessageService)
 * legge lo stato tramite SendMessageContext, una proiezione cross-aggregato
 * che unisce SubDocument+ExtractedData+OriginalDocument e non ha una casa
 * naturale qui.
 */
final class SubDocument
{
    private SubDocumentChanges $pending;

    private function __construct(
        public readonly int $id,
        public readonly int $originalDocumentId,
        public readonly string $filePath,
        public readonly int $startPage,
        public readonly int $endPage,
        public readonly string $originalFilename,
        private ReviewStatus $reviewStatus,
        private SendStatus $sendStatus,
    ) {
        $this->pending = SubDocumentChanges::none();
    }

    public static function fromRecord(SubDocumentRecord $record, ReviewStatus $reviewStatus): self
    {
        return new self(
            $record->id,
            $record->originalDocumentId,
            $record->filePath,
            $record->startPage,
            $record->endPage,
            $record->originalFilename,
            $reviewStatus,
            SendStatus::from($record->sendStatus),
        );
    }

    public function reviewStatus(): ReviewStatus
    {
        return $this->reviewStatus;
    }

    public function sendStatus(): SendStatus
    {
        return $this->sendStatus;
    }

    public function markAutoValidated(): void
    {
        $this->transitionReviewStatus(ReviewStatus::AutoValidated);
    }

    public function markNeedsReview(): void
    {
        $this->transitionReviewStatus(ReviewStatus::NeedsReview);
    }

    public function markQuarantined(string $reason): void
    {
        $this->reviewStatus = ReviewStatus::Quarantined;
        $this->pending = $this->pending
            ->withReviewStatus(ReviewStatus::Quarantined)
            ->withErrorMessage($reason);
    }

    /**
     * La precondizione (dati estratti presenti) resta responsabilita' del
     * chiamante: e' un controllo cross-aggregato (ExtractedData), non una
     * transizione interna a questo aggregato.
     */
    public function markManuallyValidated(): void
    {
        $this->transitionReviewStatus(ReviewStatus::ManuallyValidated);
    }

    /**
     * Transizione a senso unico: un secondo export non cambia lo stato.
     */
    public function markSent(): void
    {
        if ($this->sendStatus === SendStatus::Sent) {
            return;
        }

        $this->sendStatus = SendStatus::Sent;
        $this->pending = $this->pending->withSendStatus(SendStatus::Sent);
    }

    private function transitionReviewStatus(ReviewStatus $status): void
    {
        $this->reviewStatus = $status;
        $this->pending = $this->pending
            ->withReviewStatus($status)
            ->withErrorMessage(null);
    }

    /**
     * @internal consumato solo dall'adapter di persistenza.
     */
    public function pendingChanges(): SubDocumentChanges
    {
        return $this->pending;
    }
}
