<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Events\DocumentUploadAccepted;

class RecordDocumentUploadAccepted
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(DocumentUploadAccepted $event): void
    {
        $this->audit->record(
            'mvp-document-upload-accepted',
            $event->actor,
            'original_document',
            (string) $event->documentId,
            [
                'filename' => $event->filename,
                'manual_metadata' => array_filter($event->manualMetadata, static fn ($value) => $value !== null),
            ],
        );
    }
}
