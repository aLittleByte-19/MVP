<?php

namespace App\Mvp\Communications\Domain\Commands;

use App\Mvp\Support\Identity\Actor;

final class SavePromptConfigurationCommand
{
    public function __construct(
        public readonly ?string $name,
        public readonly string $prompt,
        public readonly string $tone,
        public readonly string $style,
        public readonly Actor $actor,
    ) {}
}
