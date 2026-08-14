<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Commands\SavePromptConfigurationCommand;
use App\Mvp\Communications\Domain\Events\PromptConfigurationDeleted;
use App\Mvp\Communications\Domain\Events\PromptConfigurationSaved;
use App\Mvp\Communications\Domain\Exceptions\PromptConfigurationNameTakenException;
use App\Mvp\Communications\Domain\Exceptions\PromptConfigurationNotAuthorizedException;
use App\Mvp\Communications\Domain\Ports\Inbound\PromptConfigurationUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\PromptConfigurationRepository;
use App\Mvp\Communications\Domain\ValueObjects\NewPromptConfiguration;
use App\Mvp\Support\Identity\Actor;

class PromptConfigurationService implements PromptConfigurationUseCase
{
    public function __construct(
        private readonly PromptConfigurationRepository $configurations,
        private readonly CommunicationEventDispatcherPort $events,
    ) {}

    public function save(SavePromptConfigurationCommand $command): int
    {
        $maxAttempts = 8;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $name = $this->resolveName($command->actor->tenantId, $attempt === 0 ? $command->name : null);

            try {
                $configurationId = $this->configurations->create(new NewPromptConfiguration(
                    tenantId: $command->actor->tenantId,
                    createdBy: $command->actor->id,
                    name: $name,
                    prompt: $command->prompt,
                    tone: $command->tone,
                    style: $command->style,
                ));

                $this->events->dispatch(new PromptConfigurationSaved($configurationId, $command->actor, $name));

                return $configurationId;
            } catch (PromptConfigurationNameTakenException) {
                continue;
            }
        }

        throw new PromptConfigurationNameTakenException;
    }

    public function delete(int $configurationId, Actor $actor): void
    {
        if ($this->configurations->tenantIdOf($configurationId) !== $actor->tenantId) {
            throw new PromptConfigurationNotAuthorizedException;
        }

        $this->configurations->delete($configurationId);

        $this->events->dispatch(new PromptConfigurationDeleted($configurationId, $actor));
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
