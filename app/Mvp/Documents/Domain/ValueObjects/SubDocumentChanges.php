<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Enums\SendStatus;

/**
 * Modifiche da applicare a un SubDocument, costruite un campo alla volta
 * invece che come array associativo con chiavi a stringa (vedi ADR 0010 e
 * OriginalDocumentChanges, incluso il criterio per le chiavi camelCase).
 */
final class SubDocumentChanges
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    private function __construct() {}

    public static function none(): self
    {
        return new self;
    }

    public function withReviewStatus(ReviewStatus $status): self
    {
        return $this->with('reviewStatus', $status);
    }

    public function withErrorMessage(?string $message): self
    {
        return $this->with('errorMessage', $message);
    }

    public function withSendStatus(SendStatus $status): self
    {
        return $this->with('sendStatus', $status);
    }

    public function withSendRecipientOverride(?string $recipient): self
    {
        return $this->with('sendRecipientOverride', $recipient);
    }

    public function withSendSubjectOverride(?string $subject): self
    {
        return $this->with('sendSubjectOverride', $subject);
    }

    public function withSendBodyOverride(?string $body): self
    {
        return $this->with('sendBodyOverride', $body);
    }

    private function with(string $attribute, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$attribute] = $value;

        return $clone;
    }

    public function isEmpty(): bool
    {
        return $this->attributes === [];
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
