<?php

namespace App\Mvp\Communications\Domain\Commands;

use App\Mvp\Identity\MvpUser;

final class SavePromptConfigurationCommand
{
    public function __construct(
        public readonly ?string $name,
        public readonly string $prompt,
        public readonly string $tone,
        public readonly string $style,
        public readonly MvpUser $actor,
    ) {}
}
