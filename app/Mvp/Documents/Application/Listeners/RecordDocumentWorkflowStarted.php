<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowStarted;
use App\Mvp\Observability\MetricsRecorder;

class RecordDocumentWorkflowStarted
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(DocumentWorkflowStarted $event): void
    {
        $this->audit->record(
            'mvp-document-workflow-started',
            null,
            'original_document',
            (string) $event->documentId,
            [
                'execution_arn' => $event->executionArn,
                'state_machine_arn' => $event->stateMachineArn,
                'task_queue_url' => $event->taskQueueUrl,
            ],
            tenantId: $event->tenantId,
        );
        $this->metrics->recordDomainCounter('stepfunctions_executions_started_total', [
            'state_machine' => $event->stateMachineShortName,
        ]);
    }
}
