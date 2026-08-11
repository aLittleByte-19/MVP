<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Events\DocumentWorkflowCompleted;
use App\Mvp\Documents\Domain\Ports\Inbound\FinalizeDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentChanges;
use App\Mvp\Documents\Enums\ProcessingStatus;
use Psr\Clock\ClockInterface;

class FinalizeDocumentWorkflowService implements FinalizeDocumentWorkflowUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentEventDispatcherPort $events,
        private readonly ClockInterface $clock,
    ) {}

    public function currentStatus(int $documentId): string
    {
        return $this->documents->findOriginalDocument($documentId)->processingStatus;
    }

    public function dispatchCompletionEvent(int $documentId): array
    {
        $document = $this->documents->findOriginalDocument($documentId);
        $completed = $document->processingStatus === ProcessingStatus::Completed->value;

        if ($completed && ! $document->workflowCompleted) {
            $this->documents->updateOriginalDocument($documentId, OriginalDocumentChanges::none()->withWorkflowCompletedAt($this->clock->now()));
        }

        $this->events->dispatch(new DocumentWorkflowCompleted($documentId, $document->tenantId));

        return ['event' => $completed ? 'DocumentPipelineCompleted' : 'DocumentPipelineProgressed'];
    }
}
