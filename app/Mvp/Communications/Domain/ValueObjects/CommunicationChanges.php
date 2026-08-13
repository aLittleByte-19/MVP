<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageSource;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;

/**
 * Modifiche da applicare a una Communication, costruite un campo alla volta
 * invece che come array associativo con chiavi a stringa (vedi ADR 0010 e
 * OriginalDocumentChanges, incluso il criterio per le chiavi camelCase).
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

        foreach ($fields as $key => $value) {
            $instance->attributes[self::toCamelCase($key)] = $value;
        }

        return $instance;
    }

    public function withGeneratedTitle(?string $title): self
    {
        return $this->with('generatedTitle', $title);
    }

    public function withGeneratedBody(?string $body): self
    {
        return $this->with('generatedBody', $body);
    }

    public function withImagePrompt(?string $prompt): self
    {
        return $this->with('imagePrompt', $prompt);
    }

    public function withStatus(CommunicationStatus $status): self
    {
        return $this->with('status', $status);
    }

    public function withIsFavorite(bool $isFavorite): self
    {
        return $this->with('isFavorite', $isFavorite);
    }

    public function withGenerationStatus(CommunicationGenerationStatus $status): self
    {
        return $this->with('generationStatus', $status);
    }

    public function withErrorMessage(?string $message): self
    {
        return $this->with('errorMessage', $message);
    }

    public function withWorkflowExecutionArn(?string $arn): self
    {
        return $this->with('workflowExecutionArn', $arn);
    }

    public function withWorkflowStartedAt(\DateTimeImmutable $at): self
    {
        return $this->with('workflowStartedAt', $at);
    }

    public function withWorkflowCompletedAt(?\DateTimeImmutable $at): self
    {
        return $this->with('workflowCompletedAt', $at);
    }

    public function withWorkflowFailedAt(?\DateTimeImmutable $at): self
    {
        return $this->with('workflowFailedAt', $at);
    }

    public function withWorkflowFailureReason(?string $reason): self
    {
        return $this->with('workflowFailureReason', $reason);
    }

    public function withRating(int $rating): self
    {
        return $this->with('rating', $rating);
    }

    public function withRatingComment(?string $comment): self
    {
        return $this->with('ratingComment', $comment);
    }

    public function withRatedAt(\DateTimeImmutable $at): self
    {
        return $this->with('ratedAt', $at);
    }

    public function withRatedBy(string $actorId): self
    {
        return $this->with('ratedBy', $actorId);
    }

    public function withCoverStatus(CoverImageStatus $status): self
    {
        return $this->with('coverStatus', $status);
    }

    public function withCoverError(?string $error): self
    {
        return $this->with('coverError', $error);
    }

    public function withCoverImagePath(?string $path): self
    {
        return $this->with('coverImagePath', $path);
    }

    public function withCoverImageMime(?string $mime): self
    {
        return $this->with('coverImageMime', $mime);
    }

    public function withCoverImageSize(?int $size): self
    {
        return $this->with('coverImageSize', $size);
    }

    public function withCoverImageSource(?CoverImageSource $source): self
    {
        return $this->with('coverImageSource', $source);
    }

    private function with(string $attribute, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$attribute] = $value;

        return $clone;
    }

    private static function toCamelCase(string $snakeCase): string
    {
        return lcfirst(str_replace('_', '', ucwords($snakeCase, '_')));
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
