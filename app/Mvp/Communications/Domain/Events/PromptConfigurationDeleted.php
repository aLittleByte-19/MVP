<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Identity\MvpUser;

final class PromptConfigurationDeleted
{
    public function __construct(
        public readonly int $configurationId,
        public readonly MvpUser $actor,
    ) {}
}
