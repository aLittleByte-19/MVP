<?php

namespace App\Mvp\Communications\Adapters\Primary\Workflow;

use App\Models\Communication;
use App\Mvp\Ai\BedrockService;
use App\Mvp\Communications\Domain\Ports\Inbound\FinalizeCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationCoverUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationTextUseCase;
use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Workflow\Contracts\WorkflowTaskHandler;
use Illuminate\Database\Eloquent\Model;

/**
 * Adapter primario guidato da Step Functions/SQS: traduce ogni task di
 * callback nella chiamata al caso d'uso corrispondente tramite la sua porta
 * primaria. Nessuna regola di business qui — solo dispatch per tipo di task
 * e traduzione del risultato nella forma che il runner si aspetta. Implementa
 * il contratto condiviso WorkflowTaskHandler (infrastruttura di Workflow,
 * fuori dal perimetro esagonale: vedi ADR 0010), quindi puo' legittimamente
 * risolvere/aggiornare l'aggregato Eloquent per le proprie responsabilita' di
 * adapter (resolveSubject, onFailure) — le decisioni di dominio restano nei
 * casi d'uso iniettati.
 */
class CommunicationWorkflowTaskHandler implements WorkflowTaskHandler
{
    public function __construct(
        private readonly GenerateCommunicationTextUseCase $generateText,
        private readonly GenerateCommunicationCoverUseCase $generateCover,
        private readonly FinalizeCommunicationUseCase $finalize,
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
            'communication.generate_text' => $this->generateTextStep($communication),
            'communication.generate_cover' => $this->generateCoverStep($communication),
            'communication.finalize' => $this->finalizeStep($communication),
            default => throw new \InvalidArgumentException("Task workflow non supportato: {$taskType}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function generateTextStep(Communication $communication): array
    {
        $result = $this->generateText->generate($communication->id);

        if ($result['skipped']) {
            return ['skipped' => true, 'reason' => 'text_already_generated'];
        }

        return ['skipped' => false, 'title' => $result['title']];
    }

    /**
     * @return array<string, mixed>
     */
    private function generateCoverStep(Communication $communication): array
    {
        $result = $this->generateCover->generate($communication->id);

        if ($result['skipped']) {
            return ['skipped' => true, 'reason' => 'cover_already_available'];
        }

        return ['skipped' => false, 'cover_status' => $result['coverStatus']];
    }

    /**
     * @return array<string, mixed>
     */
    private function finalizeStep(Communication $communication): array
    {
        $result = $this->finalize->finalize($communication->id);

        return [
            'skipped' => false,
            'event' => $result['event'],
            'cover_status' => $result['coverStatus'],
        ];
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

    private function asCommunication(Model $subject): Communication
    {
        if (! $subject instanceof Communication) {
            throw new \InvalidArgumentException('Il task di generazione richiede una Communication.');
        }

        return $subject;
    }
}
