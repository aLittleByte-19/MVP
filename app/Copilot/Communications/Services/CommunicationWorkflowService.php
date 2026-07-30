<?php

namespace App\Copilot\Communications\Services;

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Communications\Enums\CommunicationGenerationStatus;
use App\Copilot\Communications\Enums\CoverImageStatus;
use App\Copilot\Identity\MvpUser;
use App\Copilot\Observability\MetricsRecorder;
use App\Models\Copilot\Communication;
use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CommunicationWorkflowService
{
    public function __construct(
        private readonly SfnClient $stepFunctions,
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
        private readonly CommunicationCoverService $covers,
    ) {}

    public function start(Communication $communication, ?MvpUser $actor = null, ?Request $request = null): Communication
    {
        if ($communication->workflow_execution_arn && $communication->generation_status === CommunicationGenerationStatus::Processing) {
            return $communication;
        }

        $stateMachineArn = (string) config('services.workflow.communications_state_machine_arn');
        $taskQueueUrl = (string) config('services.workflow.communications_task_queue_url');

        if ($stateMachineArn === '' || $taskQueueUrl === '') {
            throw new \RuntimeException('Pipeline comunicazioni non configurata: COMMUNICATION_PIPELINE_STATE_MACHINE_ARN e COMMUNICATION_PIPELINE_TASK_QUEUE_URL sono obbligatori. Esegui "make refresh-runtime" per rileggere i parametri runtime.');
        }

        $input = $this->workflowInput($communication, $taskQueueUrl, $request);

        try {
            $result = $this->stepFunctions->startExecution([
                'stateMachineArn' => $stateMachineArn,
                'name' => 'mvp-comm-'.$communication->id.'-'.Str::uuid(),
                'input' => json_encode($input, JSON_THROW_ON_ERROR),
            ]);

            $executionArn = (string) $result->get('executionArn');
            $communication->update([
                'generation_status' => CommunicationGenerationStatus::Processing,
                'cover_status' => CoverImageStatus::Pending,
                'workflow_execution_arn' => $executionArn,
                'workflow_started_at' => now(),
                'workflow_completed_at' => null,
                'workflow_failed_at' => null,
                'workflow_failure_reason' => null,
                'error_message' => null,
                'cover_error' => null,
            ]);

            $this->audit->record(
                'mvp-communication-workflow-started',
                $actor,
                'communication',
                (string) $communication->id,
                [
                    'execution_arn' => $executionArn,
                    'state_machine_arn' => $stateMachineArn,
                    'task_queue_url' => $taskQueueUrl,
                ],
                $request,
                $communication->tenant_id,
            );
            $this->metrics->recordDomainCounter('stepfunctions_executions_started_total', [
                'state_machine' => $this->shortName($stateMachineArn),
            ]);

            return $communication->refresh();
        } catch (AwsException $e) {
            $this->markStartFailure($communication, $e, $actor, $request);

            throw new \RuntimeException('Impossibile avviare la pipeline Step Functions: '.$e->getAwsErrorMessage(), previous: $e);
        } catch (\Throwable $e) {
            $this->markStartFailure($communication, $e, $actor, $request);

            throw $e;
        }
    }

    /**
     * Richiede una nuova variante della bozza corrente: azzera testo e
     * copertina generati e rilancia la stessa pipeline usata per la
     * generazione iniziale, sulla stessa riga (nessuna nuova voce di storico).
     */
    public function regenerate(Communication $communication, ?MvpUser $actor = null, ?Request $request = null): Communication
    {
        $this->covers->discardForRegeneration($communication);

        $communication->update([
            'generated_title' => null,
            'generated_body' => null,
            'image_prompt' => null,
            'generation_status' => CommunicationGenerationStatus::Pending,
            'workflow_execution_arn' => null,
            'error_message' => null,
        ]);

        $this->audit->record(
            'mvp-communication-regeneration-requested',
            $actor,
            'communication',
            (string) $communication->id,
            [],
            $request,
            $communication->tenant_id,
        );

        return $this->start($communication->refresh(), $actor, $request);
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowInput(Communication $communication, string $taskQueueUrl, ?Request $request): array
    {
        return [
            'communication_id' => $communication->id,
            'tenant_id' => $communication->tenant_id,
            'correlation_id' => (string) ($request?->attributes->get('correlation_id') ?: Str::uuid()),
            'request_id' => (string) ($request?->attributes->get('request_id') ?: Str::uuid()),
            'task_queue_url' => $taskQueueUrl,
            'metadata' => [
                'valid' => true,
                'tone' => $communication->tone,
                'style' => $communication->style,
            ],
        ];
    }

    private function markStartFailure(Communication $communication, \Throwable $e, ?MvpUser $actor, ?Request $request): void
    {
        Log::error('Communication workflow start failed', [
            'communication_id' => $communication->id,
            'message' => $e->getMessage(),
        ]);

        $communication->update([
            'generation_status' => CommunicationGenerationStatus::Failed,
            'workflow_failed_at' => now(),
            'workflow_failure_reason' => $e->getMessage(),
            'error_message' => 'Avvio della generazione non disponibile.',
        ]);
        $this->audit->record(
            'mvp-communication-workflow-start-failed',
            $actor,
            'communication',
            (string) $communication->id,
            ['message' => $e->getMessage()],
            $request,
            $communication->tenant_id,
        );
        $this->metrics->recordDomainCounter('stepfunctions_executions_failed_total', [
            'state_machine' => $this->shortName((string) config('services.workflow.communications_state_machine_arn')),
        ]);
    }

    private function shortName(string $arn): string
    {
        $segments = explode(':', $arn);

        return $segments === [] ? 'unknown' : (string) end($segments);
    }
}
