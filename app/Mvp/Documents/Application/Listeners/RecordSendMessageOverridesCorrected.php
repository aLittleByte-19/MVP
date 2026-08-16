<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Events\SendMessageOverridesCorrected;

class RecordSendMessageOverridesCorrected
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(SendMessageOverridesCorrected $event): void
    {
        $this->audit->record(
            'mvp-sub-document-send-message-corrected',
            $event->actor,
            'sub_document',
            (string) $event->subDocumentId,
            ['fields' => $event->fields],
        );
    }
}
