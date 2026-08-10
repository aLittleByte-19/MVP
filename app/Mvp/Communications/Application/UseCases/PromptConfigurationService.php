<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Commands\SavePromptConfigurationCommand;
use App\Mvp\Communications\Domain\Ports\Inbound\PromptConfigurationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\PromptConfigurationRepository;
use App\Mvp\Identity\MvpUser;

class PromptConfigurationService implements PromptConfigurationUseCase
{
    public function __construct(
        private readonly PromptConfigurationRepository $configurations,
        private readonly AuditLogger $audit,
    ) {}

    public function save(SavePromptConfigurationCommand $command): int
    {
        $name = $this->resolveName($command->actor->tenantId, $command->name);

        $configurationId = $this->configurations->create([
            'tenant_id' => $command->actor->tenantId,
            'created_by' => $command->actor->id,
            'name' => $name,
            'prompt' => $command->prompt,
            'tone' => $command->tone,
            'style' => $command->style,
        ]);

        $this->audit->record(
            'mvp-prompt-configuration-saved',
            $command->actor,
            'prompt_configuration',
            (string) $configurationId,
            ['name' => $name],
        );

        return $configurationId;
    }

    public function delete(int $configurationId, MvpUser $actor): void
    {
        $this->configurations->delete($configurationId);

        $this->audit->record('mvp-prompt-configuration-deleted', $actor, 'prompt_configuration', (string) $configurationId);
    }

    /**
     * Un nome vuoto o gia' in uso per il tenant viene sostituito da
     * un'etichetta progressiva ("Senza nome (1)", "(2)", ...), mai da un errore.
     */
    private function resolveName(string $tenantId, ?string $requestedName): string
    {
        $trimmed = trim((string) $requestedName);

        if ($trimmed !== '' && ! $this->configurations->nameExists($tenantId, $trimmed)) {
            return $trimmed;
        }

        $counter = 1;

        while ($this->configurations->nameExists($tenantId, "Senza nome ({$counter})")) {
            $counter++;
        }

        return "Senza nome ({$counter})";
    }
}
