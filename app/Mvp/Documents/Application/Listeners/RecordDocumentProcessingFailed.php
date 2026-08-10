<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Events\DocumentProcessingFailed;
use App\Mvp\Documents\Enums\ProcessingStatus;
use App\Mvp\Observability\MetricsRecorder;

class RecordDocumentProcessingFailed
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(DocumentProcessingFailed $event): void
    {
        $this->audit->record(
            'mvp-document-processing-failed',
            resourceType: 'original_document',
            resourceId: (string) $event->documentId,
            metadata: ['status' => ProcessingStatus::Failed->value, 'message' => $event->message],
            tenantId: $event->tenantId,
        );

        if ($event->isInvalidAiOutput) {
            $this->metrics->recordDomainCounter('ai_outputs_invalid_total', ['operation' => $event->aiOperation ?? 'unknown']);
        }
    }
}
