<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

use App\Mvp\Documents\Enums\ReviewStatus;
use App\Mvp\Documents\Enums\SendStatus;

/**
 * Modifiche da applicare a un SubDocument, costruite un campo alla volta
 * invece che come array associativo con chiavi a stringa (vedi ADR 0010 e
 * OriginalDocumentChanges).
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
        return $this->with('review_status', $status);
    }

    public function withErrorMessage(?string $message): self
    {
        return $this->with('error_message', $message);
    }

    public function withSendStatus(SendStatus $status): self
    {
        return $this->with('send_status', $status);
    }

    public function withSendRecipientOverride(?string $recipient): self
    {
        return $this->with('send_recipient_override', $recipient);
    }

    public function withSendSubjectOverride(?string $subject): self
    {
        return $this->with('send_subject_override', $subject);
    }

    public function withSendBodyOverride(?string $body): self
    {
        return $this->with('send_body_override', $body);
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
