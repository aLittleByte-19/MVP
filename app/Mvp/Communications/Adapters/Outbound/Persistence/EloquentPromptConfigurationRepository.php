<?php

namespace App\Mvp\Communications\Adapters\Outbound\Persistence;

use App\Models\PromptConfiguration;
use App\Mvp\Communications\Domain\Ports\Outbound\PromptConfigurationRepository;

class EloquentPromptConfigurationRepository implements PromptConfigurationRepository
{
    public function nameExists(string $tenantId, string $name): bool
    {
        return PromptConfiguration::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->exists();
    }

    public function create(array $attributes): int
    {
        return PromptConfiguration::create($attributes)->id;
    }

    public function tenantIdOf(int $id): string
    {
        return PromptConfiguration::query()->findOrFail($id)->tenant_id;
    }

    public function delete(int $id): void
    {
        PromptConfiguration::query()->whereKey($id)->firstOrFail()->delete();
    }
}
