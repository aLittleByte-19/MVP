<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Events\SubDocumentExtractedDataCorrected;

class RecordSubDocumentExtractedDataCorrected
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(SubDocumentExtractedDataCorrected $event): void
    {
        $this->audit->record(
            'mvp-sub-document-extracted-data-corrected',
            $event->actor,
            'sub_document',
            (string) $event->subDocumentId,
            ['changed_fields' => $event->changedFields, 'review_status' => $event->reviewStatus],
        );
    }
}
