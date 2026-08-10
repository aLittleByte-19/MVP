<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Identity\MvpUser;

final class CommunicationDraftEdited
{
    public function __construct(
        public readonly int $communicationId,
        public readonly MvpUser $actor,
    ) {}
}
