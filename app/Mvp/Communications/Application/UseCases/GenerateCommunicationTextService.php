<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationTextUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationAiGatewayPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Observability\MetricsRecorder;

/**
 * Il testo e' la comunicazione: se fallisce, l'esecuzione fallisce
 * (nessun degrado, a differenza della copertina).
 */
class GenerateCommunicationTextService implements GenerateCommunicationTextUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationAiGatewayPort $ai,
        private readonly AuditLogger $audit,
        private readonly MetricsRecorder $metrics,
    ) {}

    public function generate(int $communicationId): array
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->generatedBody) {
            return ['skipped' => true, 'title' => null];
        }

        try {
            $generated = $this->ai->generateText($communication->prompt, $communication->tone, $communication->style);
        } catch (InvalidAiOutputException $e) {
            $this->metrics->recordDomainCounter('ai_outputs_invalid_total', ['operation' => $e->operation()]);
            $this->audit->record(
                'mvp-ai-output-invalid',
                resourceType: 'communication',
                resourceId: (string) $communicationId,
                metadata: ['operation' => $e->operation(), 'errors' => $e->errors()],
                tenantId: $communication->tenantId,
            );
            $this->communications->updateCommunication($communicationId, [
                'error_message' => 'La risposta del servizio AI non è valida. Riprova la generazione.',
            ]);

            throw $e;
        }

        $this->communications->updateCommunication($communicationId, [
            'generated_title' => $generated->title,
            'generated_body' => $generated->body,
            'image_prompt' => $generated->imagePrompt,
        ]);
        $this->audit->record(
            'mvp-communication-generated',
            resourceType: 'communication',
            resourceId: (string) $communicationId,
            metadata: ['tone' => $communication->tone, 'style' => $communication->style],
            tenantId: $communication->tenantId,
        );

        return ['skipped' => false, 'title' => $generated->title];
    }
}
