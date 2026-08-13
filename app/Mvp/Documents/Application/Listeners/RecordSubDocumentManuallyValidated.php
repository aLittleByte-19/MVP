<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Events\SubDocumentManuallyValidated;

class RecordSubDocumentManuallyValidated
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(SubDocumentManuallyValidated $event): void
    {
        $this->audit->record(
            'mvp-sub-document-manually-validated',
            $event->actor,
            'sub_document',
            (string) $event->subDocumentId,
            ['review_status' => ReviewStatus::ManuallyValidated->value],
        );
    }
}
