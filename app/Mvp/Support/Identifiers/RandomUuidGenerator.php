<?php

namespace App\Mvp\Support\Identifiers;

use Illuminate\Support\Str;

final class RandomUuidGenerator implements UniqueIdGeneratorPort
{
    public function generate(): string
    {
        return (string) Str::uuid();
    }
}
