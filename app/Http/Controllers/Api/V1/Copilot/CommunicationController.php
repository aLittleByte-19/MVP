<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Copilot\Ai\BedrockService;
use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Communications\Enums\CommunicationStatus;
use App\Copilot\Identity\PocUser;
use App\Copilot\Observability\MetricsRecorder;
use App\Copilot\Support\PocStateService;
use App\Exceptions\Copilot\AiServiceException;
use App\Exceptions\Copilot\InvalidAiOutputException;
use App\Http\Requests\Copilot\GenerateCommunicationRequest;
use App\Models\Copilot\Communication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CommunicationController
{
    /**
     * @throws AiServiceException
     */
    public function store(
        GenerateCommunicationRequest $request,
        BedrockService $bedrock,
        AuditLogger $audit,
        PocStateService $state,
        MetricsRecorder $metrics,
    ): JsonResponse {
        $validated = $request->validated();
        $actor = $this->actor($request);

        try {
            $generated = $bedrock->generateCommunication(
                $validated['prompt'],
                $validated['tone'],
                $validated['style'],
            );
        } catch (InvalidAiOutputException $e) {
            Log::warning('PoC communication generation returned invalid AI output', ['errors' => $e->errors()]);
            $metrics->recordDomainCounter('ai_outputs_invalid_total', ['operation' => $e->operation()]);
            $audit->record(
                'poc-ai-output-invalid',
                $actor,
                'communication',
                null,
                ['operation' => $e->operation(), 'errors' => $e->errors()],
                $request,
            );

            throw new AiServiceException('La risposta del servizio AI non è valida. Riprova la generazione.', 502, $e);
        } catch (\Throwable $e) {
            Log::warning('PoC communication generation failed', ['message' => $e->getMessage()]);

            throw new AiServiceException(
                BedrockService::formatUserError($e, 'Generazione non disponibile. Verifica la configurazione AI.'),
                502,
                $e
            );
        }

        $communication = Communication::create([
            'tenant_id' => $actor->tenantId,
            'created_by' => $actor->id,
            'prompt' => $validated['prompt'],
            'tone' => $validated['tone'],
            'style' => $validated['style'],
            'generated_title' => $generated['title'],
            'generated_body' => $generated['body'],
            'status' => CommunicationStatus::Draft,
        ]);
        $audit->record(
            'poc-communication-generated',
            $actor,
            'communication',
            (string) $communication->id,
            ['tone' => $communication->tone, 'style' => $communication->style],
            $request,
        );

        return response()->json([
            'message' => 'Bozza generata correttamente.',
            'communication' => $state->communication($communication),
            'state' => $state->forActor($actor),
        ], 201);
    }

    private function actor(Request $request): PocUser
    {
        $actor = $request->user();

        if (! $actor instanceof PocUser) {
            throw new \RuntimeException('PoC identity middleware did not provide a structured user.');
        }

        return $actor;
    }
}
