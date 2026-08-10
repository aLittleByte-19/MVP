<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\AiOutputRejected;
use App\Mvp\Observability\MetricsRecorder;

class RecordAiOutputRejected
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(AiOutputRejected $event): void
    {
        $this->metrics->recordDomainCounter('ai_outputs_invalid_total', ['operation' => $event->operation]);
        $this->audit->record(
            'mvp-ai-output-invalid',
            resourceType: 'communication',
            resourceId: (string) $event->communicationId,
            metadata: ['operation' => $event->operation, 'errors' => $event->errors],
            tenantId: $event->tenantId,
        );
    }
}
