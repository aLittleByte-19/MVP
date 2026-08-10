<?php

namespace App\Mvp\Documents\Domain\Events;

use App\Mvp\Identity\MvpUser;

final class SendMessageOverridesCorrected
{
    /**
     * @param  list<string>  $fields
     */
    public function __construct(
        public readonly int $subDocumentId,
        public readonly ?MvpUser $actor,
        public readonly array $fields,
    ) {}
}
