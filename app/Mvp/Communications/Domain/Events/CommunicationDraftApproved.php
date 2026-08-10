<?php

namespace App\Mvp\Communications\Domain\Events;

use App\Mvp\Identity\MvpUser;

/**
 * Emesso da CommunicationDraftUseCase::save() (UC-9): la bozza entra nello
 * storico del tenant.
 */
final class CommunicationDraftApproved
{
    public function __construct(
        public readonly int $communicationId,
        public readonly MvpUser $actor,
    ) {}
}
