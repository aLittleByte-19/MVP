<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowStartFailed;

class RecordDocumentWorkflowStartFailed
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function handle(DocumentWorkflowStartFailed $event): void
    {
        $this->audit->record(
            'mvp-document-workflow-start-failed',
            null,
            'original_document',
            (string) $event->documentId,
            ['message' => $event->message],
            tenantId: $event->tenantId,
        );
    }
}
