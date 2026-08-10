<?php

namespace Tests\DomainUnit\Communications\Fakes;

use App\Mvp\Communications\Domain\Ports\Outbound\PromptConfigurationRepository;

final class InMemoryPromptConfigurationRepository implements PromptConfigurationRepository
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private int $nextId = 1;

    public function nameExists(string $tenantId, string $name): bool
    {
        foreach ($this->rows as $row) {
            if ($row['tenant_id'] === $tenantId && $row['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    public function create(array $attributes): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = $attributes;

        return $id;
    }

    public function tenantIdOf(int $id): string
    {
        return $this->rows[$id]['tenant_id'] ?? throw new \RuntimeException("PromptConfiguration {$id} non seminata nel fake repository.");
    }

    public function delete(int $id): void
    {
        unset($this->rows[$id]);
    }

    public function has(int $id): bool
    {
        return isset($this->rows[$id]);
    }
}
