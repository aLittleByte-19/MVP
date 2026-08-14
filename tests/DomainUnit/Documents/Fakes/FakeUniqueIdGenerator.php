<?php

namespace Tests\DomainUnit\Documents\Fakes;

use App\Mvp\Support\Identifiers\UniqueIdGeneratorPort;

final class FakeUniqueIdGenerator implements UniqueIdGeneratorPort
{
    public function __construct(private readonly string $value = 'fake-id') {}

    public function generate(): string
    {
        return $this->value;
    }
}
