<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Identity\MvpUser;

final class PromptConfigurationSaved
{
    public function __construct(
        public readonly int $configurationId,
        public readonly MvpUser $actor,
        public readonly string $name,
    ) {}
}
