<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Enums\SendStatus;
use App\Mvp\Documents\Domain\Events\SendMessageExported;

class RecordSendMessageExported
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(SendMessageExported $event): void
    {
        $this->audit->record(
            'mvp-sub-document-send-exported',
            $event->actor,
            'sub_document',
            (string) $event->subDocumentId,
            ['sendStatus' => SendStatus::Sent->value],
        );
    }
}
