<?php

namespace App\Mvp\Communications\Adapters\Primary\Workflow;

use App\Mvp\Communications\Domain\Ports\Inbound\FinalizeCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationCoverUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationTextUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Workflow\Contracts\WorkflowSubject;
use App\Mvp\Workflow\Contracts\WorkflowTaskHandler;
use Psr\Clock\ClockInterface;

/**
 * Adapter primario guidato da Step Functions/SQS: traduce ogni task di
 * callback nella chiamata al caso d'uso corrispondente. Nessuna regola di
 * business qui — solo dispatch per tipo di task. Passa al runner solo
 * {@see WorkflowSubject} (id + tenant), che resta cosi' domain-agnostic.
 */
class CommunicationWorkflowTaskHandler implements WorkflowTaskHandler
{
    public function __construct(
        private readonly GenerateCommunicationTextUseCase $generateText,
        private readonly GenerateCommunicationCoverUseCase $generateCover,
        private readonly FinalizeCommunicationUseCase $finalize,
        private readonly CommunicationRepository $communications,
        private readonly ClockInterface $clock,
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

    public function pipeline(): string
    {
        return 'communications';
    }

    public function auditEventPrefix(): string
    {
        return 'mvp-communication-workflow-task-';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function resolveSubject(array $message): WorkflowSubject
    {
        $communicationId = (int) ($message['communicationId'] ?? $message['communication_id'] ?? 0);

        if ($communicationId <= 0) {
            throw new \InvalidArgumentException('Messaggio workflow non valido: communicationId e\' obbligatorio.');
        }

        $communication = $this->communications->findCommunication($communicationId);

        // Difesa in profondita': l'autorizzazione vera vive al bordo HTTP, ma un
        // tenantId nel messaggio che non corrisponde e' un segnale di messaggio
        // corrotto da non eseguire silenziosamente.
        $tenantId = $message['tenantId'] ?? $message['tenant_id'] ?? null;

        if ($tenantId !== null && $communication->tenantId !== $tenantId) {
            throw new \InvalidArgumentException("Messaggio workflow non valido: tenantId non corrisponde alla comunicazione {$communicationId}.");
        }

        return new WorkflowSubject($communication->id, $communication->tenantId);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function execute(string $taskType, WorkflowSubject $subject, array $message): array
    {
        return match ($taskType) {
            'communication.generate_text' => $this->generateTextStep($subject->id),
            'communication.generate_cover' => $this->generateCoverStep($subject->id),
            'communication.finalize' => $this->finalizeStep($subject->id),
            default => throw new \InvalidArgumentException("Task workflow non supportato: {$taskType}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function generateTextStep(int $communicationId): array
    {
        $result = $this->generateText->generate($communicationId);

        if ($result['skipped']) {
            return ['skipped' => true, 'reason' => 'text_already_generated'];
        }

        return ['skipped' => false, 'title' => $result['title']];
    }

    /**
     * @return array<string, mixed>
     */
    private function generateCoverStep(int $communicationId): array
    {
        $result = $this->generateCover->generate($communicationId);

        if ($result['skipped']) {
            return ['skipped' => true, 'reason' => 'cover_already_available'];
        }

        return ['skipped' => false, 'cover_status' => $result['coverStatus']];
    }

    /**
     * @return array<string, mixed>
     */
    private function finalizeStep(int $communicationId): array
    {
        $result = $this->finalize->finalize($communicationId);

        return [
            'skipped' => $result['skipped'],
            'event' => $result['event'],
            'cover_status' => $result['coverStatus'],
        ];
    }

    public function onFailure(WorkflowSubject $subject, string $taskType, \Throwable $e): void
    {
        $communication = $this->communications->findCommunication($subject->id);

        // Fallback per i task che falliscono prima che il caso d'uso scriva un
        // messaggio d'errore comprensibile per l'operatore.
        $communication->failGeneration($communication->errorMessage() ?: $e->getMessage(), $e->getMessage(), $this->clock->now());
        $this->communications->saveCommunication($communication);
    }
}
