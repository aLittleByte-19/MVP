<?php

namespace App\Mvp\Communications\Domain\Ports\Outbound;

use App\Mvp\Communications\Domain\ValueObjects\NewPromptConfiguration;

interface PromptConfigurationRepository
{
    public function nameExists(string $tenantId, string $name): bool;

    public function create(NewPromptConfiguration $configuration): int;

    public function tenantIdOf(int $id): string;

    public function delete(int $id): void;
}
