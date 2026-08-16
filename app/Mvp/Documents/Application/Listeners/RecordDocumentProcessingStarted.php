<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Events\DocumentProcessingStarted;

class RecordDocumentProcessingStarted
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(DocumentProcessingStarted $event): void
    {
        $this->audit->record(
            'mvp-document-processing-started',
            resourceType: 'original_document',
            resourceId: (string) $event->documentId,
            metadata: ['status' => ProcessingStatus::Processing->value],
            tenantId: $event->tenantId,
        );
    }
}
