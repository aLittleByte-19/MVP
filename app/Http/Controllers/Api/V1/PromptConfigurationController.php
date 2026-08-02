<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\SavePromptConfigurationRequest;
use App\Models\PromptConfiguration;
use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Services\PromptConfigurationNamer;
use App\Mvp\Identity\MvpUser;
use App\Mvp\Support\MvpStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preset di prompt riutilizzabili (UC-19): indipendenti da una generazione
 * effettiva, servono solo a precompilare il form con testo/tono/stile gia'
 * usati in passato.
 */
class PromptConfigurationController
{
    use ResolvesActor;

    public function store(
        SavePromptConfigurationRequest $request,
        PromptConfigurationNamer $namer,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $validated = $request->validated();

        $configuration = PromptConfiguration::create([
            'tenant_id' => $actor->tenantId,
            'created_by' => $actor->id,
            'name' => $namer->resolve($actor->tenantId, $validated['name'] ?? null),
            'prompt' => $validated['prompt'],
            'tone' => $validated['tone'],
            'style' => $validated['style'],
        ]);

        $audit->record(
            'mvp-prompt-configuration-saved',
            $actor,
            'prompt_configuration',
            (string) $configuration->id,
            ['name' => $configuration->name],
            $request,
        );

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
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertOwnership($promptConfiguration, $actor);

        $promptConfiguration->delete();

        $audit->record(
            'mvp-prompt-configuration-deleted',
            $actor,
            'prompt_configuration',
            (string) $promptConfiguration->id,
            [],
            $request,
        );

        return response()->json([
            'message' => 'Configurazione eliminata.',
            'state' => $state->forActor($actor),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    private function assertOwnership(PromptConfiguration $configuration, MvpUser $actor): void
    {
        if ($configuration->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Prompt configuration is outside the authenticated tenant scope.');
        }
    }
}
