<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Support\Identity\Actor;

final class PromptConfigurationSaved
{
    public function __construct(
        public readonly int $configurationId,
        public readonly Actor $actor,
        public readonly string $name,
    ) {}
}
