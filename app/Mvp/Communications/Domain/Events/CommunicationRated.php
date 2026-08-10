<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Identity\MvpUser;

final class CommunicationRated
{
    public function __construct(
        public readonly int $communicationId,
        public readonly MvpUser $actor,
        public readonly int $rating,
        public readonly bool $hasComment,
    ) {}
}
