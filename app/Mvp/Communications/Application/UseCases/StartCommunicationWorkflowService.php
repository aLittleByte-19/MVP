<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Events\CommunicationRegenerationRequested;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStarted;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStartFailed;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyDiscardedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationRegenerationUnavailableException;
use App\Mvp\Communications\Domain\Ports\Inbound\StartCommunicationWorkflowUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Communications\Enums\CoverImageStatus;
use App\Mvp\Identity\MvpUser;
use App\Mvp\Support\Identifiers\UniqueIdGeneratorPort;
use App\Mvp\Workflow\Ports\Outbound\WorkflowEnginePort;
use App\Mvp\Workflow\Support\WorkflowContext;
use Psr\Clock\ClockInterface;

/**
 * Applicazione: avvia (o rilancia, per rigenerazione) l'esecuzione Step
 * Functions della pipeline comunicazioni. Sostituisce
 * CommunicationWorkflowService: stessa logica, orchestrata attraverso le
 * porte del dominio invece che Eloquent/SfnClient diretti.
 */
class StartCommunicationWorkflowService implements StartCommunicationWorkflowUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationCoverStoragePort $coverStorage,
        private readonly WorkflowEnginePort $workflowEngine,
        private readonly CommunicationEventDispatcherPort $events,
        private readonly WorkflowContext $context,
        private readonly ClockInterface $clock,
        private readonly UniqueIdGeneratorPort $ids,
    ) {}

    public function start(int $communicationId, ?string $correlationId, ?string $requestId): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->workflowExecutionArn !== null && $communication->generationStatus === CommunicationGenerationStatus::Processing->value) {
            return;
        }

        $this->context->bind($requestId, $correlationId, $communication->tenantId);

        $stateMachineArn = (string) config('services.workflow.communications_state_machine_arn');
        $taskQueueUrl = (string) config('services.workflow.communications_task_queue_url');

        if ($stateMachineArn === '' || $taskQueueUrl === '') {
            throw new \RuntimeException('Pipeline comunicazioni non configurata: COMMUNICATION_PIPELINE_STATE_MACHINE_ARN e COMMUNICATION_PIPELINE_TASK_QUEUE_URL sono obbligatori. Esegui "make refresh-runtime" per rileggere i parametri runtime.');
        }

        $input = [
            'communication_id' => $communicationId,
            'tenant_id' => $communication->tenantId,
            'correlation_id' => $this->context->correlationId() ?? $this->ids->generate(),
            'request_id' => $this->context->requestId() ?? $this->ids->generate(),
            'task_queue_url' => $taskQueueUrl,
            'metadata' => ['valid' => true, 'tone' => $communication->tone, 'style' => $communication->style],
        ];

        try {
            $executionArn = $this->workflowEngine->startExecution($stateMachineArn, 'mvp-comm-'.$communicationId.'-'.$this->ids->generate(), $input);

            $this->communications->updateCommunication($communicationId, [
                'generation_status' => CommunicationGenerationStatus::Processing,
                'cover_status' => CoverImageStatus::Pending,
                'workflow_execution_arn' => $executionArn,
                'workflow_started_at' => $this->clock->now(),
                'workflow_completed_at' => null,
                'workflow_failed_at' => null,
                'workflow_failure_reason' => null,
                'error_message' => null,
                'cover_error' => null,
            ]);

            $this->events->dispatch(new CommunicationWorkflowStarted(
                $communicationId,
                $communication->tenantId,
                $executionArn,
                $stateMachineArn,
                $this->shortName($stateMachineArn),
                $taskQueueUrl,
            ));
        } catch (\Throwable $e) {
            $this->communications->updateCommunication($communicationId, [
                'generation_status' => CommunicationGenerationStatus::Failed,
                'workflow_failed_at' => $this->clock->now(),
                'workflow_failure_reason' => $e->getMessage(),
                'error_message' => 'Avvio della generazione non disponibile.',
            ]);
            $this->events->dispatch(new CommunicationWorkflowStartFailed(
                $communicationId,
                $communication->tenantId,
                $e->getMessage(),
                $this->shortName($stateMachineArn),
            ));

            throw $e;
        }
    }

    public function regenerate(int $communicationId, ?MvpUser $actor, ?string $correlationId, ?string $requestId): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->status === CommunicationStatus::Discarded->value) {
            throw new CommunicationAlreadyDiscardedException('Una bozza scartata non puo essere rigenerata.');
        }

        if (! in_array($communication->generationStatus, [CommunicationGenerationStatus::Completed->value, CommunicationGenerationStatus::Failed->value], true)) {
            throw new CommunicationRegenerationUnavailableException;
        }

        if ($communication->coverImagePath !== null) {
            $this->coverStorage->delete($communication->coverImagePath);
        }

        $this->communications->updateCommunication($communicationId, [
            'generated_title' => null,
            'generated_body' => null,
            'image_prompt' => null,
            'generation_status' => CommunicationGenerationStatus::Pending,
            'workflow_execution_arn' => null,
            'error_message' => null,
            'cover_image_path' => null,
            'cover_image_mime' => null,
            'cover_image_size' => null,
            'cover_image_source' => null,
            'cover_error' => null,
        ]);

        $this->events->dispatch(new CommunicationRegenerationRequested($communicationId, $communication->tenantId, $actor));

        $this->start($communicationId, $correlationId, $requestId);
    }

    private function shortName(string $arn): string
    {
        $segments = explode(':', $arn);

        return $segments === [] ? 'unknown' : (string) end($segments);
    }
}
