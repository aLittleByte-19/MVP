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
use App\Http\Requests\Copilot\UpdateCommunicationCoverRequest;
use App\Models\Copilot\Communication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
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

        $imageGeneration = $bedrock->generateCommunicationImageWithMeta(
            $validated['prompt'],
            $validated['tone'],
            $validated['style'],
        );

        $communication = Communication::create([
            'tenant_id' => $actor->tenantId,
            'created_by' => $actor->id,
            'prompt' => $validated['prompt'],
            'tone' => $validated['tone'],
            'style' => $validated['style'],
            'generated_title' => $generated['title'],
            'generated_body' => $generated['body'],
            'generated_cover_image' => $imageGeneration['image'],
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
            'coverImageWarning' => $imageGeneration['warning'],
            'state' => $state->forActor($actor),
        ], 201);
    }

    /**
     * @throws AuthorizationException
     */
    public function updateCoverImage(
        UpdateCommunicationCoverRequest $request,
        Communication $communication,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        /** @var UploadedFile $file */
        $file = $request->file('image');

        $communication->update([
            'generated_cover_image' => $this->uploadedImageToDataUrl($file),
        ]);

        $audit->record(
            'mvp-communication-cover-updated',
            $actor,
            'communication',
            (string) $communication->id,
            [
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ],
            $request,
        );

        return response()->json([
            'message' => 'Immagine di copertina aggiornata correttamente.',
            'communication' => $state->communication($communication->fresh()),
            'coverImageWarning' => null,
            'state' => $state->forActor($actor),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function removeCoverImage(
        Request $request,
        Communication $communication,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        $communication->update([
            'generated_cover_image' => null,
        ]);

        $audit->record(
            'mvp-communication-cover-removed',
            $actor,
            'communication',
            (string) $communication->id,
            [],
            $request,
        );

        return response()->json([
            'message' => 'Immagine di copertina rimossa.',
            'communication' => $state->communication($communication->fresh()),
            'coverImageWarning' => null,
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
    private function assertCommunicationOwnership(Communication $communication, MvpUser $actor): void
    {
        if ($communication->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Communication is outside the authenticated tenant scope.');
        }
    }

    private function uploadedImageToDataUrl(UploadedFile $file): string
    {
        $mime = $file->getMimeType() ?: 'image/png';
        $path = $file->getRealPath();
        $content = $path !== false ? file_get_contents($path) : false;

        if ($content === false || $content === '') {
            throw new \RuntimeException('Unable to read uploaded cover image content.');
        }

        return 'data:'.$mime.';base64,'.base64_encode($content);
    }
}
