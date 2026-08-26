<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\GetMvpStateRequest;
use App\Mvp\Identity\MvpUser;
use App\Mvp\Support\Identity\Actor;
use App\Mvp\Support\MvpStateService;
use App\Mvp\Support\ValueObjects\AssistantMetricsFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StateController
{
    public function __invoke(GetMvpStateRequest $request, MvpStateService $state): JsonResponse
    {
        $filters = $request->validated();

        return response()->json($state->forActor(
            $this->actor($request),
            new AssistantMetricsFilters(
                tone: isset($filters['tone']) ? (string) $filters['tone'] : null,
                style: isset($filters['style']) ? (string) $filters['style'] : null,
                dateFrom: isset($filters['dateFrom']) ? (string) $filters['dateFrom'] : null,
                dateTo: isset($filters['dateTo']) ? (string) $filters['dateTo'] : null,
            ),
        ));
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user();

        if (! $user instanceof MvpUser) {
            throw new \RuntimeException('MVP identity middleware did not provide a structured user.');
        }

        return $user->toActor();
    }
}
