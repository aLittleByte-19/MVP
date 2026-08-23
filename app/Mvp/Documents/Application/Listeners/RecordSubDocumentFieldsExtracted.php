<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Events\SubDocumentFieldsExtracted;

class RecordSubDocumentFieldsExtracted
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function handle(SubDocumentFieldsExtracted $event): void
    {
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
