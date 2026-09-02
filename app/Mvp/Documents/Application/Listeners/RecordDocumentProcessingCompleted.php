<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Events\DocumentProcessingCompleted;

class RecordDocumentProcessingCompleted
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(DocumentProcessingCompleted $event): void
    {
        $this->audit->record(
            'mvp-document-processing-completed',
            resourceType: 'original_document',
            resourceId: (string) $event->documentId,
            metadata: ['status' => ProcessingStatus::Completed->value],
            tenantId: $event->tenantId,
        );
    }
}
