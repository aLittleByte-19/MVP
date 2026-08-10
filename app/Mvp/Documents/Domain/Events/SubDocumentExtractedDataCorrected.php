<?php

namespace App\Mvp\Documents\Domain\Events;

use App\Mvp\Identity\MvpUser;

final class SubDocumentExtractedDataCorrected
{
    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public readonly int $subDocumentId,
        public readonly ?MvpUser $actor,
        public readonly array $changedFields,
        public readonly string $reviewStatus,
    ) {}
}
