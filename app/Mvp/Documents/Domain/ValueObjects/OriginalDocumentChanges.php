<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

use App\Mvp\Documents\Domain\Enums\ProcessingStatus;

/**
 * Modifiche da applicare a un OriginalDocument, costruite un campo alla
 * volta invece che come array associativo con chiavi a stringa (vedi ADR
 * 0010). Le chiavi interne sono camelCase, come i nomi dei metodi: la
 * traduzione verso i nomi delle colonne DB (snake_case) e' responsabilita'
 * dell'adapter di persistenza, non di questa classe — che quindi non
 * incorpora lo schema del database.
 */
final class OriginalDocumentChanges
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    private function __construct() {}

    public static function none(): self
    {
        return new self;
    }

    public function withProcessingStatus(ProcessingStatus $status): self
    {
        return $this->with('processingStatus', $status);
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

    public function withS3Bucket(?string $bucket): self
    {
        return $this->with('s3Bucket', $bucket);
    }

    public function withS3Key(?string $key): self
    {
        return $this->with('s3Key', $key);
    }

    public function withTextractJobId(?string $jobId): self
    {
        return $this->with('textractJobId', $jobId);
    }

    public function withOcrText(?string $text): self
    {
        return $this->with('ocrText', $text);
    }

    /**
     * @param  array<int, array{page: int, text: string, confidenceAvg: float|null}>|null  $pages
     */
    public function withOcrPages(?array $pages): self
    {
        return $this->with('ocrPages', $pages);
    }

    public function withOcrConfidenceAvg(?float $avg): self
    {
        return $this->with('ocrConfidenceAvg', $avg);
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
