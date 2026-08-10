<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Events\SubDocumentFieldsExtracted;
use App\Mvp\Documents\Enums\ReviewStatus;
use App\Mvp\Observability\MetricsRecorder;

class RecordSubDocumentFieldsExtracted
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(SubDocumentFieldsExtracted $event): void
    {
        $this->metrics->recordDomainCounter('ai_extractions_total', ['review_status' => $event->reviewStatus]);
        $this->audit->record(
            $event->reviewStatus === ReviewStatus::AutoValidated->value ? 'mvp-sub-document-auto-validated' : 'mvp-sub-document-needs-review',
            resourceType: 'sub_document',
            resourceId: (string) $event->subDocumentId,
            metadata: [
                'confidence_score' => $event->confidenceScore,
                'confidence_threshold' => $event->confidenceThreshold,
                'review_status' => $event->reviewStatus,
            ],
            tenantId: $event->tenantId,
        );
    }
}
