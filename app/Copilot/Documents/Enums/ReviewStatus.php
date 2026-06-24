<?php

namespace App\Copilot\Documents\Enums;

enum ReviewStatus: string
{
    case NeedsReview = 'needs_review';
    case AutoValidated = 'auto_validated';
    case Quarantined = 'quarantined';
    case ManuallyValidated = 'manually_validated';

    public function label(): string
    {
        return match ($this) {
            self::NeedsReview => 'Da revisionare',
            self::AutoValidated => 'Validato automaticamente',
            self::Quarantined => 'In quarantena',
            self::ManuallyValidated => 'Validato manualmente',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NeedsReview => 'warning',
            self::AutoValidated => 'info',
            self::Quarantined => 'danger',
            self::ManuallyValidated => 'success',
        };
    }
}
