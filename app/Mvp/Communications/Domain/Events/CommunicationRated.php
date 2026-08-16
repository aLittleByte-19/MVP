<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Support\Identity\Actor;

final class CommunicationRated
{
    public function __construct(
        public readonly int $communicationId,
        public readonly Actor $actor,
        public readonly int $rating,
        public readonly bool $hasComment,
    ) {}
}
