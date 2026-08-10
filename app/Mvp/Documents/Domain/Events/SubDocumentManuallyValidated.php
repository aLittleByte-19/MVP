<?php

namespace App\Mvp\Documents\Domain\Events;

use App\Mvp\Identity\MvpUser;

final class SubDocumentManuallyValidated
{
    public function __construct(
        public readonly int $subDocumentId,
        public readonly ?MvpUser $actor,
    ) {}
}
