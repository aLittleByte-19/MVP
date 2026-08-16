<?php

namespace App\Mvp\Communications\Application\Listeners;

use App\Mvp\Communications\Domain\Events\CommunicationWorkflowCompleted;
use App\Mvp\Observability\MetricsRecorder;

class RecordCommunicationWorkflowCompleted
{
    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function handle(CommunicationWorkflowCompleted $event): void
    {
        $this->metrics->recordDomainCounter('communication_workflow_completed_total');
    }
}
