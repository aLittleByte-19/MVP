<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStartFailed;
use App\Mvp\Observability\MetricsRecorder;

class RecordCommunicationWorkflowStartFailed
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(CommunicationWorkflowStartFailed $event): void
    {
        $this->audit->record(
            'mvp-communication-workflow-start-failed',
            null,
            'communication',
            (string) $event->communicationId,
            ['message' => $event->message],
            tenantId: $event->tenantId,
        );
        $this->metrics->recordDomainCounter('stepfunctions_executions_failed_total', [
            'reason' => 'start_failure',
            'state_machine' => $event->stateMachineShortName,
        ]);
    }
}
