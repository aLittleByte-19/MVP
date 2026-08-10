<?php

namespace App\Mvp\Communications\Domain\Commands;

use App\Mvp\Identity\MvpUser;

final class GenerateCommunicationCommand
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $tone,
        public readonly string $style,
        public readonly MvpUser $actor,
    ) {}
}
