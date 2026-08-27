<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Events\CommunicationRegenerationRequested;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStarted;
use App\Mvp\Communications\Domain\Events\CommunicationWorkflowStartFailed;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotAuthorizedException;
use App\Mvp\Communications\Domain\Ports\Inbound\StartCommunicationWorkflowUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Support\Identifiers\UniqueIdGeneratorPort;
use App\Mvp\Support\Identity\Actor;
use App\Mvp\Support\Persistence\TransactionManagerPort;
use App\Mvp\Workflow\Ports\Outbound\WorkflowEnginePort;
use App\Mvp\Workflow\Support\StateMachineName;
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
 * `WorkflowContext` e' una classe pura (nessuna dipendenza da Illuminate,
 * vedi il suo docblock): `start()`/`regenerate()` sono istanziabili ed
 * eseguibili in un test Pest puro, vedi StartCommunicationWorkflowServiceTest.
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
        private readonly TransactionManagerPort $transactions,
        private readonly string $stateMachineArn,
        private readonly string $taskQueueUrl,
    ) {}

    /**
     * Il controllo "e' gia' in corso?" e la scrittura che lo rende vero
     * girano dentro lo stesso lock pessimistico sulla riga
     * (`findCommunicationForUpdate`, come CommunicationDraftService::save()):
     * senza, due richieste quasi simultanee (un doppio invio, un retry che si
     * sovrappone) potevano superare entrambe il controllo e avviare due
     * esecuzioni Step Functions per la stessa comunicazione, una delle quali
     * resta orfana e non tracciata.
     *
     * La chiamata di rete a Step Functions resta dentro la transazione (non
     * e' il pattern ideale — terrebbe il lock per la durata della chiamata —
     * ma per il volume di questa pipeline e' un compromesso corretto e molto
     * piu' sicuro dell'assenza totale di lock). Gli eventi di dominio restano
     * dispatchati DOPO che la transazione ha commesso (stesso schema di
     * favorite()/unfavorite()): se venissero dispatchati da dentro la
     * closure e poi si rilanciasse l'eccezione dal ramo di fallimento, la
     * transazione andrebbe in rollback e annullerebbe anche il salvataggio
     * dello stato "fallito" che quel ramo ha appena scritto.
     */
    public function start(int $communicationId, ?string $correlationId, ?string $requestId): void
    {
        $startedEvent = null;
        $failedEvent = null;
        $failure = null;

        $this->transactions->run(function () use ($communicationId, $correlationId, $requestId, &$startedEvent, &$failedEvent, &$failure): void {
            $communication = $this->communications->findCommunicationForUpdate($communicationId);

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

                $startedEvent = new CommunicationWorkflowStarted(
                    $communicationId,
                    $communication->tenantId,
                    $executionArn,
                    $stateMachineArn,
                    StateMachineName::fromArn($stateMachineArn),
                    $taskQueueUrl,
                );
            } catch (\Throwable $e) {
                $communication->failGeneration('Avvio della generazione non disponibile.', $e->getMessage(), $this->clock->now());
                $this->communications->saveCommunication($communication);

                $failedEvent = new CommunicationWorkflowStartFailed(
                    $communicationId,
                    $communication->tenantId,
                    $e->getMessage(),
                    StateMachineName::fromArn($stateMachineArn),
                );
                $failure = $e;
            }
        });

        if ($startedEvent !== null) {
            $this->events->dispatch($startedEvent);

            return;
        }

        if ($failedEvent !== null && $failure !== null) {
            $this->events->dispatch($failedEvent);

            throw $failure;
        }
    }

    /**
     * Difesa in profondita' (vedi il docblock di CommunicationDraftService):
     * a differenza di favorite/save/discard/update qui non serve un lock —
     * non c'e' un controllo di stato concorrente da proteggere, il lock su
     * `start()` (chiamato subito dopo) resta la difesa contro un doppio
     * avvio — ma il controllo tenant sì, visto che l'`Actor` e' gia' in mano
     * e prima non veniva usato per verificarlo.
     */
    public function regenerate(int $communicationId, Actor $actor, ?string $correlationId, ?string $requestId): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->tenantId !== $actor->tenantId) {
            throw new CommunicationNotAuthorizedException;
        }

        $oldCoverPath = $communication->coverImagePath();

        $communication->regenerate();

        if ($oldCoverPath !== null) {
            $this->coverStorage->delete($oldCoverPath);
        }

        $this->communications->saveCommunication($communication);

        $this->events->dispatch(new CommunicationRegenerationRequested($communicationId, $communication->tenantId, $actor));

        $this->start($communicationId, $correlationId, $requestId);
    }
}
