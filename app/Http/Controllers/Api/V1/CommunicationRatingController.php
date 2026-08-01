<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesCommunications;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\RateCommunicationRequest;
use App\Models\Communication;
use App\Mvp\Audit\Services\AuditLogger;
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
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

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
}
