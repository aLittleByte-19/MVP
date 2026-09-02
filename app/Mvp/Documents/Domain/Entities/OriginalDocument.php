<?php

namespace App\Mvp\Documents\Domain\Entities;

use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentChanges;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentRecord;

/**
 * Entità (non solo VO): governa le proprie transizioni di processingStatus
 * invece di lasciare a ogni caso d'uso il compito di scrivere lo stato
 * giusto a mano (ADR 0010).
 *
 * L'invariante non è "transizione valida si'/no" ma "questi campi si
 * muovono sempre insieme": `fail()` consolida in un solo posto
 * processingStatus, workflowFailedAt e workflowFailureReason.
 *
 * Deliberatamente NON governa i campi OCR (ocrText/ocrPages/
 * ocrConfidenceAvg/textractJobId): RunOcrService li scrive con
 * OriginalDocumentChanges grezzo — non sono una transizione di stato, solo
 * dati letti dal gateway OCR.
 */
final class OriginalDocument
{
    private OriginalDocumentChanges $pending;

    private function __construct(
        public readonly int $id,
        public readonly string $tenantId,
        public readonly string $filePath,
        public readonly ?string $manualDocumentType,
        public readonly ?string $manualCompanyName,
        public readonly ?int $manualReferenceMonth,
        public readonly ?int $manualReferenceYear,
        private ProcessingStatus $processingStatus,
        public readonly ?string $ocrText,
        public readonly array $ocrPages,
        public readonly ?float $ocrConfidenceAvg,
        private ?string $s3Bucket,
        private ?string $s3Key,
        private bool $workflowCompleted,
        private ?string $workflowExecutionArn,
        private ?string $errorMessage,
        public readonly ?string $originalFilename = null,
    ) {
        $this->pending = OriginalDocumentChanges::none();
    }

    public static function fromRecord(OriginalDocumentRecord $record): self
    {
        return new self(
            $record->id,
            $record->tenantId,
            $record->filePath,
            $record->manualDocumentType,
            $record->manualCompanyName,
            $record->manualReferenceMonth,
            $record->manualReferenceYear,
            ProcessingStatus::from($record->processingStatus),
            $record->ocrText,
            $record->ocrPages,
            $record->ocrConfidenceAvg,
            $record->s3Bucket,
            $record->s3Key,
            $record->workflowCompleted,
            $record->workflowExecutionArn,
            $record->errorMessage,
            $record->originalFilename,
        );
    }

    public function processingStatus(): ProcessingStatus
    {
        return $this->processingStatus;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function s3Bucket(): ?string
    {
        return $this->s3Bucket;
    }

    public function s3Key(): ?string
    {
        return $this->s3Key;
    }

    public function workflowExecutionArn(): ?string
    {
        return $this->workflowExecutionArn;
    }

    public function workflowCompleted(): bool
    {
        return $this->workflowCompleted;
    }

    public function hasManualUploadMetadata(): bool
    {
        return $this->manualDocumentType !== null
            || $this->manualCompanyName !== null
            || $this->manualReferenceMonth !== null
            || $this->manualReferenceYear !== null;
    }

    public function startProcessing(string $executionArn, string $s3Bucket, string $s3Key, \DateTimeImmutable $startedAt): void
    {
        $this->processingStatus = ProcessingStatus::Processing;
        $this->workflowExecutionArn = $executionArn;
        $this->s3Bucket = $s3Bucket;
        $this->s3Key = $s3Key;
        $this->errorMessage = null;
        $this->pending = $this->pending
            ->withProcessingStatus(ProcessingStatus::Processing)
            ->withWorkflowExecutionArn($executionArn)
            ->withWorkflowStartedAt($startedAt)
            ->withWorkflowCompletedAt(null)
            ->withWorkflowFailedAt(null)
            ->withWorkflowFailureReason(null)
            ->withS3Bucket($s3Bucket)
            ->withS3Key($s3Key)
            ->withErrorMessage(null);
    }

    /**
     * $errorMessage e' il messaggio leggibile dall'operatore (deciso dal
     * chiamante, che conosce il proprio contesto); $technicalReason e' il
     * dettaglio tecnico per il troubleshooting (spesso $e->getMessage()).
     */
    public function fail(string $errorMessage, ?string $technicalReason, \DateTimeImmutable $failedAt): void
    {
        $this->processingStatus = ProcessingStatus::Failed;
        $this->errorMessage = $errorMessage;
        $this->pending = $this->pending
            ->withProcessingStatus(ProcessingStatus::Failed)
            ->withWorkflowFailedAt($failedAt)
            ->withWorkflowFailureReason($technicalReason)
            ->withErrorMessage($errorMessage);
    }

    public function complete(): void
    {
        $this->processingStatus = ProcessingStatus::Completed;
        $this->errorMessage = null;
        $this->pending = $this->pending
            ->withProcessingStatus(ProcessingStatus::Completed)
            ->withErrorMessage(null);
    }

    public function markWorkflowCompleted(\DateTimeImmutable $completedAt): void
    {
        if ($this->workflowCompleted) {
            return;
        }

        $this->workflowCompleted = true;
        $this->pending = $this->pending->withWorkflowCompletedAt($completedAt);
    }

    /**
     * @internal consumato solo dall'adapter di persistenza.
     */
    public function pendingChanges(): OriginalDocumentChanges
    {
        return $this->pending;
    }
}
