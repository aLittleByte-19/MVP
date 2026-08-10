<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Events\AiOutputRejected;
use App\Mvp\Documents\Enums\ReviewStatus;
use App\Mvp\Observability\MetricsRecorder;

class RecordAiOutputRejected
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(AiOutputRejected $event): void
    {
        $this->audit->record(
            'mvp-sub-document-ai-output-quarantined',
            resourceType: 'sub_document',
            resourceId: (string) $event->subDocumentId,
            metadata: ['operation' => $event->operation, 'errors' => $event->errors, 'review_status' => ReviewStatus::Quarantined->value],
            tenantId: $event->tenantId,
        );
        $this->metrics->recordDomainCounter('ai_outputs_invalid_total', ['operation' => $event->operation]);
    }
}
