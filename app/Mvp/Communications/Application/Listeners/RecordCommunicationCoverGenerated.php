<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Enums\CoverImageSource;
use App\Mvp\Communications\Domain\Events\CommunicationCoverGenerated;
use App\Mvp\Observability\MetricsRecorder;

class RecordCommunicationCoverGenerated
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(CommunicationCoverGenerated $event): void
    {
        $this->metrics->recordDomainCounter('communication_covers_generated_total', ['source' => CoverImageSource::Ai->value]);
        $this->audit->record(
            'mvp-communication-cover-generated',
            resourceType: 'communication',
            resourceId: (string) $event->communicationId,
            metadata: ['mime' => $event->mime],
            tenantId: $event->tenantId,
        );
    }
}
