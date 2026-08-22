<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Documents\Domain\Events\DocumentWorkflowStartFailed;
use App\Mvp\Observability\MetricsRecorder;

class RecordDocumentWorkflowStartFailed
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function handle(DocumentWorkflowStartFailed $event): void
    {
        $this->audit->record(
            'mvp-document-workflow-start-failed',
            null,
            'original_document',
            (string) $event->documentId,
            ['message' => $event->message],
            tenantId: $event->tenantId,
        );
        $this->metrics->recordDomainCounter('stepfunctions_executions_failed_total', [
            'reason' => 'start_failure',
            'state_machine' => $event->stateMachineShortName,
        ]);
    }
}
