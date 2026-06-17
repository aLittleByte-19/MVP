<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Copilot\Identity\PocUser;
use App\Copilot\Support\PocStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StateController
{
    public function __invoke(Request $request, PocStateService $state): JsonResponse
    {
        return response()->json($state->forActor($this->actor($request)));
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
