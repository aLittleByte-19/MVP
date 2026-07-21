<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Copilot\Ai\BedrockService;
use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Communications\Enums\CommunicationStatus;
use App\Copilot\Identity\MvpUser;
use App\Copilot\Observability\MetricsRecorder;
use App\Copilot\Support\MvpStateService;
use App\Exceptions\Copilot\AiServiceException;
use App\Exceptions\Copilot\InvalidAiOutputException;
use App\Http\Requests\Copilot\GenerateCommunicationRequest;
use App\Http\Requests\Copilot\RateCommunicationRequest;
use App\Models\Copilot\Communication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CommunicationController
{
    /**
     * @throws AiServiceException
     */
    public function store(
        GenerateCommunicationRequest $request,
        BedrockService $bedrock,
        AuditLogger $audit,
        MvpStateService $state,
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
            Log::warning('MVP communication generation returned invalid AI output', ['errors' => $e->errors()]);
            $metrics->recordDomainCounter('ai_outputs_invalid_total', ['operation' => $e->operation()]);
            $audit->record(
                'mvp-ai-output-invalid',
                $actor,
                'communication',
                null,
                ['operation' => $e->operation(), 'errors' => $e->errors()],
                $request,
            );

            throw new AiServiceException('La risposta del servizio AI non è valida. Riprova la generazione.', 502, $e);
        } catch (\Throwable $e) {
            Log::warning('MVP communication generation failed', ['message' => $e->getMessage()]);

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
            'mvp-communication-generated',
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

    public function rate(
        RateCommunicationRequest $request,
        Communication $communication,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorizeCommunication($communication, $actor);

        if ($communication->rating !== null) {
            throw ValidationException::withMessages([
                'rating' => ['La valutazione è già stata registrata per questa bozza.'],
            ]);
        }

        $validated = $request->validated();
        $comment = array_key_exists('comment', $validated)
            ? (is_string($validated['comment']) ? trim($validated['comment']) : null)
            : null;

        if ($comment === '') {
            $comment = null;
        }

        $communication->update([
            'rating' => $validated['rating'],
            'rating_comment' => $comment,
            'rated_at' => now(),
            'rated_by' => $actor->id,
        ]);

        $audit->record(
            'mvp-communication-rated',
            $actor,
            'communication',
            (string) $communication->id,
            [
                'rating' => $communication->rating,
                'has_comment' => $communication->rating_comment !== null,
            ],
            $request,
        );

        return response()->json([
            'message' => 'Valutazione registrata con successo.',
            'communication' => $state->communication($communication->fresh()),
            'state' => $state->forActor($actor),
        ]);
    }

    private function actor(Request $request): MvpUser
    {
        $actor = $request->user();

        if (! $actor instanceof MvpUser) {
            throw new \RuntimeException('MVP identity middleware did not provide a structured user.');
        }

        return $actor;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeCommunication(Communication $communication, MvpUser $actor): void
    {
        if ($communication->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Comunicazione non autorizzata per il tenant corrente.');
        }
    }
}
