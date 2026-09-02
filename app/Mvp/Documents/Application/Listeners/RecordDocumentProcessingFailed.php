<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Events\DocumentProcessingFailed;

class RecordDocumentProcessingFailed
{
    public function __construct(
        private readonly AuditLogger $audit,
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
    }
}
