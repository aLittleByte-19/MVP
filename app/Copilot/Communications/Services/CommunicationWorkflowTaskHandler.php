<?php

namespace App\Copilot\Communications\Services;

use App\Copilot\Ai\BedrockService;
use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Communications\Enums\CommunicationGenerationStatus;
use App\Copilot\Communications\Enums\CoverImageStatus;
use App\Copilot\Observability\MetricsRecorder;
use App\Copilot\Workflow\Contracts\WorkflowTaskHandler;
use App\Exceptions\Copilot\InvalidAiOutputException;
use App\Models\Copilot\Communication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CommunicationWorkflowTaskHandler implements WorkflowTaskHandler
{
    public function __construct(
        private readonly BedrockService $bedrock,
        private readonly CommunicationCoverService $covers,
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    /**
     * @return array<int, string>
     */
    public function taskTypes(): array
    {
        return ['communication.generate_text', 'communication.generate_cover', 'communication.finalize'];
    }

    public function subjectType(): string
    {
        return 'communication';
    }

    public function auditEventPrefix(): string
    {
        return 'mvp-communication-workflow-task-';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function resolveSubject(array $message): Model
    {
        $communicationId = (int) ($message['communicationId'] ?? $message['communication_id'] ?? 0);

        if ($communicationId <= 0) {
            throw new \InvalidArgumentException('Messaggio workflow non valido: communicationId e\' obbligatorio.');
        }

        return Communication::query()->findOrFail($communicationId);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function execute(string $taskType, Model $subject, array $message): array
    {
        $communication = $this->asCommunication($subject);

        return match ($taskType) {
            'communication.generate_text' => $this->generateText($communication),
            'communication.generate_cover' => $this->generateCover($communication),
            'communication.finalize' => $this->finalize($communication),
            default => throw new \InvalidArgumentException("Task workflow non supportato: {$taskType}"),
        };
    }

    public function onFailure(Model $subject, string $taskType, \Throwable $e): void
    {
        $communication = $this->asCommunication($subject);

        $communication->update([
            'generation_status' => CommunicationGenerationStatus::Failed,
            'workflow_failed_at' => now(),
            'workflow_failure_reason' => $e->getMessage(),
            'error_message' => $communication->error_message
                ?: BedrockService::formatUserError($e, 'Generazione non disponibile. Riprova o verifica la configurazione AI.'),
        ]);
    }

    /**
     * Il testo e' la comunicazione: se fallisce, l'esecuzione fallisce.
     *
     * @return array<string, mixed>
     */
    private function generateText(Communication $communication): array
    {
        if ($communication->generated_body) {
            return ['skipped' => true, 'reason' => 'text_already_generated'];
        }

        try {
            $generated = $this->bedrock->generateCommunication(
                $communication->prompt,
                $communication->tone,
                $communication->style,
            );
        } catch (InvalidAiOutputException $e) {
            $this->metrics->recordDomainCounter('ai_outputs_invalid_total', ['operation' => $e->operation()]);
            $this->audit->record(
                'mvp-ai-output-invalid',
                resourceType: 'communication',
                resourceId: (string) $communication->id,
                metadata: ['operation' => $e->operation(), 'errors' => $e->errors()],
                tenantId: $communication->tenant_id,
            );
            $communication->update([
                'error_message' => 'La risposta del servizio AI non è valida. Riprova la generazione.',
            ]);

            throw $e;
        }

        $communication->update([
            'generated_title' => $generated['title'],
            'generated_body' => $generated['body'],
            'image_prompt' => $generated['image_prompt'],
        ]);
        $this->audit->record(
            'mvp-communication-generated',
            resourceType: 'communication',
            resourceId: (string) $communication->id,
            metadata: ['tone' => $communication->tone, 'style' => $communication->style],
            tenantId: $communication->tenant_id,
        );

        return ['skipped' => false, 'title' => $generated['title']];
    }

    /**
     * La copertina e' un arricchimento: un fallimento viene registrato sulla
     * comunicazione e il task si chiude comunque con successo, altrimenti una
     * comunicazione valida verrebbe persa per un'immagine mancante.
     *
     * @return array<string, mixed>
     */
    private function generateCover(Communication $communication): array
    {
        if ($communication->cover_status === CoverImageStatus::Ready) {
            return ['skipped' => true, 'reason' => 'cover_already_available'];
        }

        $this->covers->markProcessing($communication);

        try {
            $image = $this->bedrock->generateCommunicationImageWithMeta(
                $communication->prompt,
                $communication->tone,
                $communication->style,
                $communication->image_prompt,
            );
        } catch (\Throwable $e) {
            Log::warning('Communication cover generation failed', [
                'communication_id' => $communication->id,
                'message' => $e->getMessage(),
            ]);
            $this->degradeCover($communication, 'Copertina AI non disponibile per un errore del servizio immagini.', 'model_error');

            return ['skipped' => false, 'cover_status' => CoverImageStatus::Failed->value];
        }

        if ($image['bytes'] === null) {
            $this->degradeCover(
                $communication,
                $image['warning'] ?? 'Copertina AI non disponibile al momento.',
                $image['reason'] ?? 'model_error',
            );

            return ['skipped' => false, 'cover_status' => CoverImageStatus::Failed->value];
        }

        try {
            $this->covers->storeGenerated($communication, $image['bytes'], $image['mime']);
        } catch (\Throwable $e) {
            Log::error('Communication cover storage failed', [
                'communication_id' => $communication->id,
                'message' => $e->getMessage(),
            ]);
            $this->degradeCover($communication, 'Copertina non disponibile: storage non raggiungibile.', 'storage_error');

            return ['skipped' => false, 'cover_status' => CoverImageStatus::Failed->value];
        }

        $this->audit->record(
            'mvp-communication-cover-generated',
            resourceType: 'communication',
            resourceId: (string) $communication->id,
            metadata: ['mime' => $image['mime']],
            tenantId: $communication->tenant_id,
        );

        return ['skipped' => false, 'cover_status' => CoverImageStatus::Ready->value];
    }

    /**
     * @return array<string, mixed>
     */
    private function finalize(Communication $communication): array
    {
        // La copertina resta pending solo se il task e' stato saltato dal ramo
        // di degrado dell'ASL (timeout o worker caduto): va chiusa qui, altrimenti
        // la SPA continuerebbe ad aspettarla.
        if (in_array($communication->cover_status, [CoverImageStatus::Pending, CoverImageStatus::Processing], true)) {
            $this->degradeCover($communication, 'Copertina AI non disponibile: generazione interrotta.', 'timeout');
        }

        $communication->update([
            'generation_status' => CommunicationGenerationStatus::Completed,
            'workflow_completed_at' => now(),
        ]);
        $this->metrics->recordDomainCounter('communication_workflow_completed_total');

        return [
            'skipped' => false,
            'event' => 'CommunicationPipelineCompleted',
            'cover_status' => $communication->refresh()->cover_status->value,
        ];
    }

    private function degradeCover(Communication $communication, string $warning, string $reason): void
    {
        $this->covers->markFailed($communication, $warning, $reason);
        $this->audit->record(
            'mvp-communication-cover-degraded',
            resourceType: 'communication',
            resourceId: (string) $communication->id,
            metadata: ['reason' => $reason],
            tenantId: $communication->tenant_id,
        );
    }

    private function asCommunication(Model $subject): Communication
    {
        if (! $subject instanceof Communication) {
            throw new \InvalidArgumentException('Il task di generazione richiede una Communication.');
        }

        return $subject;
    }
}
