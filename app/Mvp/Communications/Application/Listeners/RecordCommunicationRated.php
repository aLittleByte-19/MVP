<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationRated;

class RecordCommunicationRated
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CommunicationRated $event): void
    {
        $this->audit->record(
            'mvp-communication-rated',
            $event->actor,
            'communication',
            (string) $event->communicationId,
            ['rating' => $event->rating, 'has_comment' => $event->hasComment],
        );
    }
}
