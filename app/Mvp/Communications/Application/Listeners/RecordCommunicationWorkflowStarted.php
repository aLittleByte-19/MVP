<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStarted;
use App\Mvp\Observability\MetricsRecorder;

class RecordCommunicationWorkflowStarted
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(CommunicationWorkflowStarted $event): void
    {
        $this->audit->record(
            'mvp-communication-workflow-started',
            null,
            'communication',
            (string) $event->communicationId,
            ['execution_arn' => $event->executionArn, 'state_machine_arn' => $event->stateMachineArn, 'task_queue_url' => $event->taskQueueUrl],
            tenantId: $event->tenantId,
        );
        $this->metrics->recordDomainCounter('stepfunctions_executions_started_total', [
            'state_machine' => $event->stateMachineShortName,
        ]);
    }
}
