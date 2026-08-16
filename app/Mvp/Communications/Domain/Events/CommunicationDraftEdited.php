<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Support\Identity\Actor;

final class CommunicationDraftEdited
{
    public function __construct(
        public readonly int $communicationId,
        public readonly Actor $actor,
    ) {}
}
