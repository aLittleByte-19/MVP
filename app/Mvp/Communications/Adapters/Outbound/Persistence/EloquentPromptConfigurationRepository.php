<?php

namespace App\Mvp\Communications\Adapters\Outbound\Persistence;

use App\Models\PromptConfiguration;
use App\Mvp\Communications\Domain\Exceptions\PromptConfigurationNameTakenException;
use App\Mvp\Communications\Domain\Ports\Outbound\PromptConfigurationRepository;
use App\Mvp\Communications\Domain\ValueObjects\NewPromptConfiguration;
use Illuminate\Database\UniqueConstraintViolationException;

class EloquentPromptConfigurationRepository implements PromptConfigurationRepository
{
    public function nameExists(string $tenantId, string $name): bool
    {
        return PromptConfiguration::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $name)
            ->exists();
    }

    public function create(NewPromptConfiguration $configuration): int
    {
        try {
            return PromptConfiguration::create($configuration->toArray())->id;
        } catch (UniqueConstraintViolationException) {
            throw new PromptConfigurationNameTakenException;
        }
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
