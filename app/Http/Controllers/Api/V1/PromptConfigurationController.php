<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\SavePromptConfigurationRequest;
use App\Models\PromptConfiguration;
use App\Mvp\Communications\Domain\Commands\SavePromptConfigurationCommand;
use App\Mvp\Communications\Domain\Ports\Inbound\PromptConfigurationUseCase;
use App\Mvp\Support\Identity\Actor;
use App\Mvp\Support\MvpStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Adapter primario HTTP: preset di prompt riutilizzabili (UC-19). Nessuna
 * regola di business qui (vedi ADR 0010); l'ownership resta un controllo di
 * trasporto, come per PromptConfigurationUseCase::delete().
 */
class PromptConfigurationController
{
    use ResolvesActor;

    public function store(
        SavePromptConfigurationRequest $request,
        PromptConfigurationUseCase $configurations,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $validated = $request->validated();

        $configurationId = $configurations->save(new SavePromptConfigurationCommand(
            name: $validated['name'] ?? null,
            prompt: $validated['prompt'],
            tone: $validated['tone'],
            style: $validated['style'],
            actor: $actor,
        ));

        $configuration = PromptConfiguration::query()->findOrFail($configurationId);

        return response()->json([
            'message' => 'Configurazione salvata.',
            'configuration' => $state->promptConfiguration($configuration),
            'state' => $state->forActor($actor),
        ], 201);
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(
        Request $request,
        PromptConfiguration $promptConfiguration,
        PromptConfigurationUseCase $configurations,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertOwnership($promptConfiguration, $actor);

        $configurations->delete($promptConfiguration->id, $actor);

        return response()->json([
            'message' => 'Configurazione eliminata.',
            'state' => $state->forActor($actor),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    private function assertOwnership(PromptConfiguration $configuration, Actor $actor): void
    {
        if ($configuration->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Prompt configuration is outside the authenticated tenant scope.');
        }
    }
}
