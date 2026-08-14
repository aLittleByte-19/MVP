<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Events\CommunicationRegenerationRequested;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStarted;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStartFailed;
use App\Mvp\Communications\Domain\Ports\Inbound\StartCommunicationWorkflowUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Support\Identifiers\UniqueIdGeneratorPort;
use App\Mvp\Support\Identity\Actor;
use App\Mvp\Workflow\Ports\Outbound\WorkflowEnginePort;
use App\Mvp\Workflow\Support\WorkflowContext;
use Psr\Clock\ClockInterface;

/**
 * Applicazione: avvia (o rilancia, per rigenerazione) l'esecuzione Step
 * Functions della pipeline comunicazioni. Sostituisce
 * CommunicationWorkflowService: stessa logica, orchestrata attraverso le
 * porte del dominio invece che Eloquent/SfnClient diretti.
 *
 * ARN e coda sono risolti una volta sola nel service provider e passati al
 * costruttore, invece di leggere `config()` qui dentro — stesso pattern
 * gia' usato per la soglia di confidenza di ExtractSubDocumentFieldsService
 * e per il prefisso di storage di UpdateCommunicationCoverService/
 * GenerateCommunicationCoverService (vedi ADR 0010).
 *
 * Non basta a rendere `start()` testabile in DomainUnit: `WorkflowContext::bind()`
 * chiama la facade `Log`, che richiede un container Laravel booted. Il
 * blocco residuo per un test di dominio puro è questo, non più `config()` —
 * non risolto in questo giro.
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
        private readonly string $stateMachineArn,
        private readonly string $taskQueueUrl,
    ) {}

    public function start(int $communicationId, ?string $correlationId, ?string $requestId): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->workflowExecutionArn() !== null && $communication->generationStatus() === CommunicationGenerationStatus::Processing) {
            return;
        }

        $this->context->bind($requestId, $correlationId, $communication->tenantId);

        $stateMachineArn = $this->stateMachineArn;
        $taskQueueUrl = $this->taskQueueUrl;

        $input = [
            'communication_id' => $communicationId,
            'tenant_id' => $communication->tenantId,
            'correlation_id' => $this->context->correlationId() ?? $this->ids->generate(),
            'request_id' => $this->context->requestId() ?? $this->ids->generate(),
            'task_queue_url' => $taskQueueUrl,
            'metadata' => ['valid' => true, 'tone' => $communication->tone, 'style' => $communication->style],
        ];

        try {
            if ($stateMachineArn === '' || $taskQueueUrl === '') {
                throw new \RuntimeException('Pipeline comunicazioni non configurata: COMMUNICATION_PIPELINE_STATE_MACHINE_ARN e COMMUNICATION_PIPELINE_TASK_QUEUE_URL sono obbligatori. Esegui "make refresh-runtime" per rileggere i parametri runtime.');
            }

            $executionArn = $this->workflowEngine->startExecution($stateMachineArn, 'mvp-comm-'.$communicationId.'-'.$this->ids->generate(), $input);

            $communication->startGeneration($executionArn, $this->clock->now());
            $this->communications->saveCommunication($communication);

            $this->events->dispatch(new CommunicationWorkflowStarted(
                $communicationId,
                $communication->tenantId,
                $executionArn,
                $stateMachineArn,
                $this->shortName($stateMachineArn),
                $taskQueueUrl,
            ));
        } catch (\Throwable $e) {
            $communication->failGeneration('Avvio della generazione non disponibile.', $e->getMessage(), $this->clock->now());
            $this->communications->saveCommunication($communication);
            $this->events->dispatch(new CommunicationWorkflowStartFailed(
                $communicationId,
                $communication->tenantId,
                $e->getMessage(),
                $this->shortName($stateMachineArn),
            ));

            throw $e;
        }
    }

    public function regenerate(int $communicationId, ?Actor $actor, ?string $correlationId, ?string $requestId): void
    {
        $communication = $this->communications->findCommunication($communicationId);
        $oldCoverPath = $communication->coverImagePath();

        $communication->regenerate();

        if ($oldCoverPath !== null) {
            $this->coverStorage->delete($oldCoverPath);
        }

        $this->communications->saveCommunication($communication);

        $this->events->dispatch(new CommunicationRegenerationRequested($communicationId, $communication->tenantId, $actor));

        $this->start($communicationId, $correlationId, $requestId);
    }

    private function shortName(string $arn): string
    {
        $segments = explode(':', $arn);

        return $segments === [] ? 'unknown' : (string) end($segments);
    }
}
