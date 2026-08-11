<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Communications\Enums\CoverImageSource;
use App\Mvp\Communications\Enums\CoverImageStatus;

/**
 * Modifiche da applicare a una Communication, costruite un campo alla volta
 * invece che come array associativo con chiavi a stringa: il nome della
 * colonna DB resta un dettaglio di questa classe (Domain, non Application),
 * non qualcosa che ogni caso d'uso deve scrivere a mano (vedi ADR 0010 e
 * OriginalDocumentChanges, stesso pattern sul lato Documents). L'adapter di
 * persistenza consuma `toArray()` cosi' com'e' — nessuna seconda traduzione.
 */
final class CommunicationChanges
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    private function __construct() {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Unico punto di conversione da array grezzo: CommunicationDraftBuilder
     * (Domain/ValueObjects/) restituisce gia' array snake_case per i passi
     * generate_text/generate_cover — non un pattern da riusare altrove.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function fromRawFields(array $fields): self
    {
        $instance = new self;
        $instance->attributes = $fields;

        return $instance;
    }

    public function withGeneratedTitle(?string $title): self
    {
        return $this->with('generated_title', $title);
    }

    public function withGeneratedBody(?string $body): self
    {
        return $this->with('generated_body', $body);
    }

    public function withImagePrompt(?string $prompt): self
    {
        return $this->with('image_prompt', $prompt);
    }

    public function withStatus(CommunicationStatus $status): self
    {
        return $this->with('status', $status);
    }

    public function withIsFavorite(bool $isFavorite): self
    {
        return $this->with('is_favorite', $isFavorite);
    }

    public function withGenerationStatus(CommunicationGenerationStatus $status): self
    {
        return $this->with('generation_status', $status);
    }

    public function withErrorMessage(?string $message): self
    {
        return $this->with('error_message', $message);
    }

    public function withWorkflowExecutionArn(?string $arn): self
    {
        return $this->with('workflow_execution_arn', $arn);
    }

    public function withWorkflowStartedAt(\DateTimeImmutable $at): self
    {
        return $this->with('workflow_started_at', $at);
    }

    public function withWorkflowCompletedAt(?\DateTimeImmutable $at): self
    {
        return $this->with('workflow_completed_at', $at);
    }

    public function withWorkflowFailedAt(?\DateTimeImmutable $at): self
    {
        return $this->with('workflow_failed_at', $at);
    }

    public function withWorkflowFailureReason(?string $reason): self
    {
        return $this->with('workflow_failure_reason', $reason);
    }

    public function withRating(int $rating): self
    {
        return $this->with('rating', $rating);
    }

    public function withRatingComment(?string $comment): self
    {
        return $this->with('rating_comment', $comment);
    }

    public function withRatedAt(\DateTimeImmutable $at): self
    {
        return $this->with('rated_at', $at);
    }

    public function withRatedBy(string $actorId): self
    {
        return $this->with('rated_by', $actorId);
    }

    public function withCoverStatus(CoverImageStatus $status): self
    {
        return $this->with('cover_status', $status);
    }

    public function withCoverError(?string $error): self
    {
        return $this->with('cover_error', $error);
    }

    public function withCoverImagePath(?string $path): self
    {
        return $this->with('cover_image_path', $path);
    }

    public function withCoverImageMime(?string $mime): self
    {
        return $this->with('cover_image_mime', $mime);
    }

    public function withCoverImageSize(?int $size): self
    {
        return $this->with('cover_image_size', $size);
    }

    public function withCoverImageSource(?CoverImageSource $source): self
    {
        return $this->with('cover_image_source', $source);
    }

    private function with(string $attribute, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$attribute] = $value;

        return $clone;
    }

    /**
     * @internal consumato solo dall'adapter di persistenza.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
