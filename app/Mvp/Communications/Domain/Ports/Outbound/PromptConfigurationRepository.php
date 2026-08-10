<?php

namespace App\Mvp\Communications\Domain\Ports\Outbound;

interface PromptConfigurationRepository
{
    public function nameExists(string $tenantId, string $name): bool;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): int;

    public function tenantIdOf(int $id): string;

    public function delete(int $id): void;
}
