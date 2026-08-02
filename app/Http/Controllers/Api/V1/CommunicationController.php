<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesCommunications;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\GenerateCommunicationRequest;
use App\Http\Requests\ListCommunicationsRequest;
use App\Http\Requests\UpdateCommunicationRequest;
use App\Models\Communication;
use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Communications\Enums\CoverImageStatus;
use App\Mvp\Communications\Services\CommunicationWorkflowService;
use App\Mvp\Support\MvpStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo di vita della bozza: creazione, modifica manuale, nuova variante, scarto ed eliminazione definitiva.
 */
class CommunicationController
{
    use AuthorizesCommunications, ResolvesActor;

    /**
     * Storico del tenant, filtrabile (UC-15..UC-18). Una bozza vi entra solo
     * dopo un salvataggio esplicito (UC-9): finche' resta in stato draft (o
     * dopo uno scarto) non compare qui, e' visibile solo nell'area di lavoro
     * corrente dell'operatore.
     */
    public function index(ListCommunicationsRequest $request, MvpStateService $state): JsonResponse
    {
        $actor = $this->actor($request);
        $filters = $request->validated();

        $query = Communication::query()
            ->where('tenant_id', $actor->tenantId)
            ->where('status', CommunicationStatus::Approved);

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

        $this->assertCommunicationIsEditable($communication);

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
     * Rende la bozza visibile nello storico (UC-9): resta comunque
     * modificabile e rigenerabile come prima, il salvataggio decide solo
     * cosa compare nell'elenco, non blocca il contenuto.
     *
     * @throws AuthorizationException
     */
    public function save(
        Request $request,
        Communication $communication,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        abort_if(
            $communication->status !== CommunicationStatus::Draft,
            422,
            'Solo le bozze in stato draft possono essere salvate nello storico.',
        );

        $communication->update(['status' => CommunicationStatus::Approved]);

        $audit->record(
            'mvp-communication-saved',
            $actor,
            'communication',
            (string) $communication->id,
            [],
            $request,
        );

        return response()->json([
            'message' => 'Bozza salvata nello storico.',
            'communication' => $state->communication($communication->refresh()),
            'state' => $state->forActor($actor),
        ]);
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
