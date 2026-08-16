<?php

namespace App\Mvp\Documents\Application\Listeners;

use App\Mvp\Documents\Domain\Events\DocumentWorkflowCompleted;
use App\Mvp\Observability\MetricsRecorder;

class RecordDocumentWorkflowCompleted
{
    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function handle(DocumentWorkflowCompleted $event): void
    {
        $this->metrics->recordDomainCounter('document_workflow_completed_total');
    }
}
