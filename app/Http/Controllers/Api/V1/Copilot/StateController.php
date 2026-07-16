<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Copilot\Identity\MvpUser;
use App\Copilot\Support\MvpStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StateController
{
    public function __invoke(Request $request, MvpStateService $state): JsonResponse
    {
        return response()->json($state->forActor($this->actor($request)));
    }

    private function actor(Request $request): MvpUser
    {
        $actor = $request->user();

        if (! $actor instanceof MvpUser) {
            throw new \RuntimeException('MVP identity middleware did not provide a structured user.');
        }

        return $actor;
    }
}
