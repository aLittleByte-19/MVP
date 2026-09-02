<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Events\AiOutputRejected;

class RecordAiOutputRejected
{
    public function __construct(
        private readonly AuditLogger $audit,
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
    }
}
