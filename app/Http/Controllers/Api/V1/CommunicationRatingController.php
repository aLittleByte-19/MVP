<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesCommunications;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\RateCommunicationRequest;
use App\Models\Communication;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyRatedException;
use App\Mvp\Communications\Domain\Ports\Inbound\RateCommunicationUseCase;
use App\Mvp\Support\MvpStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Valutazione 1-5 con commento opzionale, una sola per generazione.
 */
class CommunicationRatingController
{
    use AuthorizesCommunications, ResolvesActor;

    public function rate(
        RateCommunicationRequest $request,
        Communication $communication,
        RateCommunicationUseCase $rate,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        $validated = $request->validated();

        try {
            $rate->rate($communication->id, (int) $validated['rating'], $validated['comment'] ?? null, $actor);
        } catch (CommunicationAlreadyRatedException $e) {
            throw ValidationException::withMessages(['rating' => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Valutazione registrata con successo.',
            'communication' => $state->communication($communication->fresh()),
            'state' => $state->forActor($actor),
        ]);
    }
}
