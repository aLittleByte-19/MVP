<?php

namespace App\Mvp\Communications\Domain\Entities;

use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageSource;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyDiscardedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyRatedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotDraftException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationRegenerationUnavailableException;
use App\Mvp\Communications\Domain\Exceptions\CoverPrecedesTextException;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationChanges;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationRecord;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationImage;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationText;

/**
 * Entità ricca (non un VO): ogni metodo verifica la propria guardia e
 * accumula il delta in {@see CommunicationChanges}, letto dall'adapter di
 * persistenza (ADR 0010).
 *
 * Non governa: il marcatore transitorio `coverStatus = Processing` scritto
 * da GenerateCommunicationCoverService prima della chiamata AI, ne'
 * l'`errorMessage` scritto da GenerateCommunicationTextService nei propri
 * rami di errore (riconciliato poi da failGeneration()).
 */
final class Communication
{
    private CommunicationChanges $pending;

    private function __construct(
        public readonly int $id,
        public readonly string $tenantId,
        public readonly string $prompt,
        public readonly string $tone,
        public readonly string $style,
        private ?string $generatedTitle,
        private ?string $generatedBody,
        private ?string $imagePrompt,
        private CommunicationGenerationStatus $generationStatus,
        private ?string $coverImagePath,
        private ?string $coverImageMime,
        private CoverImageStatus $coverStatus,
        private CommunicationStatus $status,
        private bool $isFavorite,
        private ?int $rating,
        private ?string $workflowExecutionArn,
        private ?string $coverError,
        private ?string $errorMessage,
    ) {
        $this->pending = CommunicationChanges::none();
    }

    public static function fromRecord(CommunicationRecord $record): self
    {
        return new self(
            $record->id,
            $record->tenantId,
            $record->prompt,
            $record->tone,
            $record->style,
            $record->generatedTitle,
            $record->generatedBody,
            $record->imagePrompt,
            CommunicationGenerationStatus::from($record->generationStatus),
            $record->coverImagePath,
            $record->coverImageMime,
            CoverImageStatus::from($record->coverStatus),
            CommunicationStatus::from($record->status),
            $record->isFavorite,
            $record->rating,
            $record->workflowExecutionArn,
            $record->coverError,
            $record->errorMessage,
        );
    }

    public function generatedTitle(): ?string
    {
        return $this->generatedTitle;
    }

    public function generatedBody(): ?string
    {
        return $this->generatedBody;
    }

    public function imagePrompt(): ?string
    {
        return $this->imagePrompt;
    }

    public function generationStatus(): CommunicationGenerationStatus
    {
        return $this->generationStatus;
    }

    public function coverImagePath(): ?string
    {
        return $this->coverImagePath;
    }

    public function coverImageMime(): ?string
    {
        return $this->coverImageMime;
    }

    public function coverStatus(): CoverImageStatus
    {
        return $this->coverStatus;
    }

    public function status(): CommunicationStatus
    {
        return $this->status;
    }

    public function isFavorite(): bool
    {
        return $this->isFavorite;
    }

    public function rating(): ?int
    {
        return $this->rating;
    }

    public function workflowExecutionArn(): ?string
    {
        return $this->workflowExecutionArn;
    }

    public function coverError(): ?string
    {
        return $this->coverError;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function hasGeneratedText(): bool
    {
        return $this->generatedBody !== null;
    }

    public function isReadyForExport(): bool
    {
        return $this->generationStatus === CommunicationGenerationStatus::Completed
            && $this->status !== CommunicationStatus::Discarded;
    }

    public function isEditable(): bool
    {
        return $this->status !== CommunicationStatus::Discarded;
    }

    public function favorite(): void
    {
        if ($this->isFavorite) {
            throw new CommunicationAlreadyFavoritedException;
        }

        $this->isFavorite = true;
        $this->pending = $this->pending->withIsFavorite(true);
    }

    public function unfavorite(): void
    {
        if (! $this->isFavorite) {
            throw new CommunicationNotFavoritedException;
        }

        $this->isFavorite = false;
        $this->pending = $this->pending->withIsFavorite(false);
    }

    public function updateDraft(string $title, string $body): void
    {
        $this->assertEditable();

        $this->generatedTitle = $title;
        $this->generatedBody = $body;
        $this->pending = $this->pending->withGeneratedTitle($title)->withGeneratedBody($body);
    }

    public function approve(): void
    {
        if ($this->status !== CommunicationStatus::Draft) {
            throw new CommunicationNotDraftException;
        }

        $this->status = CommunicationStatus::Approved;
        $this->pending = $this->pending->withStatus(CommunicationStatus::Approved);
    }

    public function discard(): void
    {
        if ($this->status === CommunicationStatus::Discarded) {
            throw new CommunicationAlreadyDiscardedException;
        }

        $this->status = CommunicationStatus::Discarded;
        $this->pending = $this->pending->withStatus(CommunicationStatus::Discarded);
    }

    public function rate(int $rating, ?string $comment, string $actorId, \DateTimeImmutable $ratedAt): void
    {
        if ($this->rating !== null) {
            throw new CommunicationAlreadyRatedException;
        }

        $this->rating = $rating;
        $this->pending = $this->pending
            ->withRating($rating)
            ->withRatingComment($comment)
            ->withRatedAt($ratedAt)
            ->withRatedBy($actorId);
    }

    public function replaceCover(string $path, string $mime, int $size): void
    {
        $this->assertEditable();

        $this->coverImagePath = $path;
        $this->coverImageMime = $mime;
        $this->coverStatus = CoverImageStatus::Ready;
        $this->coverError = null;
        $this->pending = $this->pending
            ->withCoverImagePath($path)
            ->withCoverImageMime($mime)
            ->withCoverImageSize($size)
            ->withCoverImageSource(CoverImageSource::Manual)
            ->withCoverStatus(CoverImageStatus::Ready)
            ->withCoverError(null);
    }

    public function removeCover(): void
    {
        $this->assertEditable();

        $this->coverImagePath = null;
        $this->coverImageMime = null;
        $this->coverStatus = CoverImageStatus::Removed;
        $this->coverError = null;
        $this->pending = $this->pending
            ->withCoverImagePath(null)
            ->withCoverImageMime(null)
            ->withCoverImageSize(null)
            ->withCoverImageSource(null)
            ->withCoverStatus(CoverImageStatus::Removed)
            ->withCoverError(null);
    }

    public function applyGeneratedText(GeneratedCommunicationText $generated): void
    {
        $this->generatedTitle = $generated->title;
        $this->generatedBody = $generated->body;
        $this->imagePrompt = $generated->imagePrompt;
        $this->pending = $this->pending
            ->withGeneratedTitle($generated->title)
            ->withGeneratedBody($generated->body)
            ->withImagePrompt($generated->imagePrompt);
    }

    /**
     * @throws CoverPrecedesTextException se il testo non e' ancora stato
     *                                    generato: la copertina usa l'image_prompt scritto dal modello
     *                                    testuale nello stesso passo che genera titolo e corpo.
     */
    public function applyGeneratedCover(GeneratedCommunicationImage $image, string $path): void
    {
        if (! $this->hasGeneratedText()) {
            throw new CoverPrecedesTextException;
        }

        $this->coverImagePath = $path;
        $this->coverImageMime = $image->mime;
        $this->coverStatus = CoverImageStatus::Ready;
        $this->coverError = null;
        $this->pending = $this->pending
            ->withCoverImagePath($path)
            ->withCoverImageMime($image->mime)
            ->withCoverImageSize(strlen($image->bytes ?? ''))
            ->withCoverImageSource(CoverImageSource::Ai)
            ->withCoverStatus(CoverImageStatus::Ready)
            ->withCoverError(null);
    }

    public function degradeCover(string $warning): void
    {
        $this->coverStatus = CoverImageStatus::Failed;
        $this->coverError = $warning;
        $this->pending = $this->pending->withCoverStatus(CoverImageStatus::Failed)->withCoverError($warning);
    }

    public function startGeneration(string $executionArn, \DateTimeImmutable $startedAt): void
    {
        $this->generationStatus = CommunicationGenerationStatus::Processing;
        $this->coverStatus = CoverImageStatus::Pending;
        $this->workflowExecutionArn = $executionArn;
        $this->errorMessage = null;
        $this->coverError = null;
        $this->pending = $this->pending
            ->withGenerationStatus(CommunicationGenerationStatus::Processing)
            ->withCoverStatus(CoverImageStatus::Pending)
            ->withWorkflowExecutionArn($executionArn)
            ->withWorkflowStartedAt($startedAt)
            ->withWorkflowCompletedAt(null)
            ->withWorkflowFailedAt(null)
            ->withWorkflowFailureReason(null)
            ->withErrorMessage(null)
            ->withCoverError(null);
    }

    /**
     * $errorMessage e' il messaggio leggibile dall'operatore (deciso dal
     * chiamante, che conosce il proprio contesto); $technicalReason e' il
     * dettaglio tecnico per il troubleshooting (spesso $e->getMessage()).
     */
    public function failGeneration(string $errorMessage, ?string $technicalReason, \DateTimeImmutable $failedAt): void
    {
        $this->generationStatus = CommunicationGenerationStatus::Failed;
        $this->errorMessage = $errorMessage;
        $this->pending = $this->pending
            ->withGenerationStatus(CommunicationGenerationStatus::Failed)
            ->withWorkflowFailedAt($failedAt)
            ->withWorkflowFailureReason($technicalReason)
            ->withErrorMessage($errorMessage);
    }

    public function completeGeneration(\DateTimeImmutable $completedAt): void
    {
        $this->generationStatus = CommunicationGenerationStatus::Completed;
        $this->pending = $this->pending
            ->withGenerationStatus(CommunicationGenerationStatus::Completed)
            ->withWorkflowCompletedAt($completedAt);
    }

    public function regenerate(): void
    {
        if ($this->status === CommunicationStatus::Discarded) {
            throw new CommunicationAlreadyDiscardedException('Una bozza scartata non puo essere rigenerata.');
        }

        if (! in_array($this->generationStatus, [CommunicationGenerationStatus::Completed, CommunicationGenerationStatus::Failed], true)) {
            throw new CommunicationRegenerationUnavailableException;
        }

        $this->generatedTitle = null;
        $this->generatedBody = null;
        $this->imagePrompt = null;
        $this->generationStatus = CommunicationGenerationStatus::Pending;
        $this->workflowExecutionArn = null;
        $this->errorMessage = null;
        $this->coverImagePath = null;
        $this->coverImageMime = null;
        $this->coverStatus = CoverImageStatus::Pending;
        $this->coverError = null;
        $this->pending = $this->pending
            ->withGeneratedTitle(null)
            ->withGeneratedBody(null)
            ->withImagePrompt(null)
            ->withGenerationStatus(CommunicationGenerationStatus::Pending)
            ->withWorkflowExecutionArn(null)
            ->withErrorMessage(null)
            ->withCoverImagePath(null)
            ->withCoverImageMime(null)
            ->withCoverImageSize(null)
            ->withCoverImageSource(null)
            ->withCoverStatus(CoverImageStatus::Pending)
            ->withCoverError(null);
    }

    private function assertEditable(): void
    {
        if ($this->status === CommunicationStatus::Discarded) {
            throw new CommunicationNotEditableException;
        }
    }

    /**
     * @internal consumato solo dall'adapter di persistenza.
     */
    public function pendingChanges(): CommunicationChanges
    {
        return $this->pending;
    }
}
