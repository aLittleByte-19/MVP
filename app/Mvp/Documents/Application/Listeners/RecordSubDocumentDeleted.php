<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Events\SubDocumentDeleted;

class RecordSubDocumentDeleted
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(SubDocumentDeleted $event): void
    {
        $this->audit->record(
            'mvp-sub-document-deleted',
            $event->actor,
            'sub_document',
            (string) $event->subDocumentId,
            ['original_document_id' => $event->originalDocumentId],
        );
    }
}
