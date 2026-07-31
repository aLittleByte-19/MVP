<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Communications\Enums\CommunicationGenerationStatus;
use App\Copilot\Communications\Enums\CommunicationStatus;
use App\Copilot\Communications\Enums\CoverImageStatus;
use App\Copilot\Communications\Services\CommunicationWorkflowService;
use App\Copilot\Support\MvpStateService;
use App\Http\Controllers\Api\V1\Copilot\Concerns\AuthorizesCommunications;
use App\Http\Controllers\Api\V1\Copilot\Concerns\ResolvesActor;
use App\Http\Requests\Copilot\GenerateCommunicationRequest;
use App\Http\Requests\Copilot\ListCommunicationsRequest;
use App\Http\Requests\Copilot\UpdateCommunicationRequest;
use App\Models\Copilot\Communication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo di vita della bozza: creazione, modifica manuale, nuova variante, scarto ed eliminazione definitiva.
 */
class CommunicationController
{
    use AuthorizesCommunications, ResolvesActor;

    /**
     * Storico delle bozze del tenant, filtrabile (UC-15..UC-18). Come lo
     * storico esposto in `state.assistant.history`, esclude le bozze scartate:
     * restano tracciate ma fuori dall'area di lavoro dell'operatore.
     */
    public function index(ListCommunicationsRequest $request, MvpStateService $state): JsonResponse
    {
        $actor = $this->actor($request);
        $filters = $request->validated();

        $query = Communication::query()
            ->where('tenant_id', $actor->tenantId)
            ->where('status', '!=', CommunicationStatus::Discarded);

        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where('prompt', 'like', '%'.$keyword.'%');
        }

        foreach (['tone', 'style'] as $exact) {
            if ($value = trim((string) ($filters[$exact] ?? ''))) {
                $query->where($exact, $value);
            }
        }

        if ($date = $filters['date'] ?? null) {
            $query->whereDate('created_at', $date);
        }

        $paginator = $query->latest()->paginate(
            perPage: (int) ($filters['perPage'] ?? 10),
            page: (int) ($filters['page'] ?? 1),
        );

        return response()->json([
            'items' => collect($paginator->items())->map(fn ($communication) => $state->communication($communication))->values()->all(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
        ]);
    }

    public function store(
        GenerateCommunicationRequest $request,
        CommunicationWorkflowService $workflow,
        AuditLogger $audit,
    ): JsonResponse {
        $validated = $request->validated();
        $actor = $this->actor($request);

        $communication = Communication::create([
            'tenant_id' => $actor->tenantId,
            'created_by' => $actor->id,
            'prompt' => $validated['prompt'],
            'tone' => $validated['tone'],
            'style' => $validated['style'],
            'generation_status' => CommunicationGenerationStatus::Pending,
            'cover_status' => CoverImageStatus::Pending,
            'status' => CommunicationStatus::Draft,
        ]);

        $audit->record(
            'mvp-communication-generation-requested',
            $actor,
            'communication',
            (string) $communication->id,
            ['tone' => $communication->tone, 'style' => $communication->style],
            $request,
        );

        $workflow->start($communication, $actor, $request);

        return response()->json([
            'message' => 'Generazione avviata.',
            'communicationId' => $communication->id,
            // URL relativo: la SPA e' servita in HTTPS dietro Traefik, che termina
            // il TLS e inoltra in HTTP. Un URL assoluto verrebbe generato con
            // schema "http://" e bloccato dal browser come mixed-content.
            'streamUrl' => route('api.v1.communications.stream', ['communication' => $communication->id], false),
        ], 202);
    }

    public function update(
        UpdateCommunicationRequest $request,
        Communication $communication,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        if ($communication->status !== CommunicationStatus::Draft) {
            throw ValidationException::withMessages([
                'communication' => ['Solo le bozze in stato draft sono modificabili.'],
            ]);
        }

        $validated = $request->validated();

        $communication->update([
            'generated_title' => $validated['title'],
            'generated_body' => $validated['body'],
        ]);
        $audit->record(
            'mvp-communication-edited',
            $actor,
            'communication',
            (string) $communication->id,
            ['fields' => ['title', 'body']],
            $request,
        );

        return response()->json([
            'message' => 'Bozza aggiornata.',
            'communication' => $state->communication($communication->fresh()),
            'state' => $state->forActor($actor),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function regenerate(
        Request $request,
        Communication $communication,
        CommunicationWorkflowService $workflow,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);
        $this->assertCommunicationCanRegenerate($communication);

        $communication = $workflow->regenerate($communication, $actor, $request);

        return response()->json([
            'message' => 'Rigenerazione avviata.',
            'communicationId' => $communication->id,
            'streamUrl' => route('api.v1.communications.stream', ['communication' => $communication->id], false),
        ], 202);
    }

    /**
     * @throws AuthorizationException
     */
    public function discard(
        Request $request,
        Communication $communication,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        abort_if($communication->status === CommunicationStatus::Discarded, 422, 'La bozza risulta gia scartata.');

        $communication->update(['status' => CommunicationStatus::Discarded]);

        $audit->record(
            'mvp-communication-discarded',
            $actor,
            'communication',
            (string) $communication->id,
            [],
            $request,
        );

        return response()->json([
            'message' => 'Bozza scartata.',
            'communication' => $state->communication($communication->refresh()),
            'state' => $state->forActor($actor),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(
        Request $request,
        Communication $communication,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        $coverPath = $communication->cover_image_path;
        $disk = (string) config('mvp.communications.cover_disk', config('filesystems.default', 'local'));

        $communication->delete();

        if ($coverPath) {
            Storage::disk($disk)->delete($coverPath);
        }

        $audit->record(
            'mvp-communication-deleted',
            $actor,
            'communication',
            (string) $communication->id,
            [],
            $request,
        );

        return response()->json([
            'message' => 'Generazione eliminata dallo storico.',
            'state' => $state->forActor($actor),
        ]);
    }
}
