<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesCommunications;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\GenerateCommunicationRequest;
use App\Http\Requests\ListCommunicationsRequest;
use App\Http\Requests\UpdateCommunicationRequest;
use App\Models\Communication;
use App\Mvp\Communications\Domain\Commands\GenerateCommunicationCommand;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyDiscardedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotDraftException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationRegenerationUnavailableException;
use App\Mvp\Communications\Domain\Ports\Inbound\CommunicationDraftUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\DeleteCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\GenerateCommunicationUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\ListCommunicationsUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\StartCommunicationWorkflowUseCase;
use App\Mvp\Support\MvpStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Adapter primario HTTP: traduce le richieste nei casi d'uso del dominio
 * Communications tramite le loro porte primarie. Nessuna regola di business
 * qui (vedi ADR 0010). Le liste e lo shaping delle risposte richiedono di
 * ricaricare l'aggregato Eloquent per MvpStateService, che resta fuori dal
 * perimetro esagonale — stessa eccezione dichiarata gia' applicata a
 * DocumentController.
 */
class CommunicationController
{
    use AuthorizesCommunications, ResolvesActor;

    public function index(
        ListCommunicationsRequest $request,
        ListCommunicationsUseCase $list,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $filters = $request->validated();

        $page = $list->list(
            $actor->tenantId,
            $filters,
            (int) ($filters['page'] ?? 1),
            (int) ($filters['perPage'] ?? 10),
        );

        $communicationsById = Communication::query()
            ->whereIn('id', $page->communicationIds)
            ->get()
            ->keyBy('id');

        $items = collect($page->communicationIds)
            ->map(fn (int $id) => $communicationsById->get($id))
            ->filter()
            ->map(fn (Communication $communication) => $state->communication($communication))
            ->values();

        return response()->json([
            'items' => $items->all(),
            'total' => $page->total,
            'page' => $page->page,
            'perPage' => $page->perPage,
        ]);
    }

    public function store(
        GenerateCommunicationRequest $request,
        GenerateCommunicationUseCase $generate,
        StartCommunicationWorkflowUseCase $startWorkflow,
    ): JsonResponse {
        $validated = $request->validated();
        $actor = $this->actor($request);
        $correlationId = $request->attributes->get('correlation_id');
        $requestId = $request->attributes->get('request_id');

        $communicationId = $generate->generate(new GenerateCommunicationCommand(
            prompt: $validated['prompt'],
            tone: $validated['tone'],
            style: $validated['style'],
            actor: $actor,
        ));

        $startWorkflow->start($communicationId, $correlationId, $requestId);

        return response()->json([
            'message' => 'Generazione avviata.',
            'communicationId' => $communicationId,
            // URL relativo: la SPA e' servita in HTTPS dietro Traefik, che termina
            // il TLS e inoltra in HTTP. Un URL assoluto verrebbe generato con
            // schema "http://" e bloccato dal browser come mixed-content.
            'streamUrl' => route('api.v1.communications.stream', ['communication' => $communicationId], false),
        ], 202);
    }

    /**
     * @throws AuthorizationException
     */
    public function favorite(Request $request, Communication $communication, CommunicationDraftUseCase $draft, MvpStateService $state): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        try {
            $draft->favorite($communication->id, $actor);
        } catch (CommunicationAlreadyFavoritedException $e) {
            throw ValidationException::withMessages(['communication' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Generazione aggiunta ai preferiti.',
            'communication' => $state->communication($communication->fresh()),
            'state' => $state->forActor($actor),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function unfavorite(Request $request, Communication $communication, CommunicationDraftUseCase $draft, MvpStateService $state): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        try {
            $draft->unfavorite($communication->id, $actor);
        } catch (CommunicationNotFavoritedException $e) {
            throw ValidationException::withMessages(['communication' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Generazione rimossa dai preferiti.',
            'communication' => $state->communication($communication->fresh()),
            'state' => $state->forActor($actor),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function update(
        UpdateCommunicationRequest $request,
        Communication $communication,
        CommunicationDraftUseCase $draft,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        $validated = $request->validated();

        try {
            $draft->update($communication->id, $validated['title'], $validated['body'], $actor);
        } catch (CommunicationNotEditableException $e) {
            throw ValidationException::withMessages(['communication' => [$e->getMessage()]]);
        }

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
        StartCommunicationWorkflowUseCase $startWorkflow,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);
        $correlationId = $request->attributes->get('correlation_id');
        $requestId = $request->attributes->get('request_id');

        try {
            $startWorkflow->regenerate($communication->id, $actor, $correlationId, $requestId);
        } catch (CommunicationAlreadyDiscardedException $e) {
            abort(422, $e->getMessage());
        } catch (CommunicationRegenerationUnavailableException $e) {
            abort(409, $e->getMessage());
        }

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
        CommunicationDraftUseCase $draft,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        try {
            $draft->save($communication->id, $actor);
        } catch (CommunicationNotDraftException $e) {
            abort(422, $e->getMessage());
        }

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
        CommunicationDraftUseCase $draft,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        try {
            $draft->discard($communication->id, $actor);
        } catch (CommunicationAlreadyDiscardedException $e) {
            abort(422, $e->getMessage());
        }

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
        DeleteCommunicationUseCase $delete,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        $delete->delete($communication->id, $actor);

        return response()->json([
            'message' => 'Generazione eliminata dallo storico.',
            'state' => $state->forActor($actor),
        ]);
    }
}
