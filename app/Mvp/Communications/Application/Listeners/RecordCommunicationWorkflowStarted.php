<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStarted;

class RecordCommunicationWorkflowStarted
{
    public function __construct(
        private readonly AuditLogger $audit,
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
    }
}
