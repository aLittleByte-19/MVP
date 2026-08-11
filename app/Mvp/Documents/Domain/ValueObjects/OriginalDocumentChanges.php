<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

use App\Mvp\Documents\Enums\ProcessingStatus;

/**
 * Modifiche da applicare a un OriginalDocument, costruite un campo alla
 * volta invece che come array associativo con chiavi a stringa: il nome
 * della colonna DB resta un dettaglio di questa classe (Domain, non
 * Application), non qualcosa che ogni caso d'uso deve scrivere a mano
 * (vedi ADR 0010). L'adapter di persistenza consuma `toArray()` cosi'
 * com'e' — nessuna seconda traduzione dei nomi dei campi.
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
        return $this->with('processing_status', $status);
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

    public function withS3Bucket(?string $bucket): self
    {
        return $this->with('s3_bucket', $bucket);
    }

    public function withS3Key(?string $key): self
    {
        return $this->with('s3_key', $key);
    }

    public function withTextractJobId(?string $jobId): self
    {
        return $this->with('textract_job_id', $jobId);
    }

    public function withOcrText(?string $text): self
    {
        return $this->with('ocr_text', $text);
    }

    /**
     * @param  array<int, array{page: int, text: string, confidenceAvg: float|null}>|null  $pages
     */
    public function withOcrPages(?array $pages): self
    {
        return $this->with('ocr_pages', $pages);
    }

    public function withOcrConfidenceAvg(?float $avg): self
    {
        return $this->with('ocr_confidence_avg', $avg);
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
