<?php

namespace App\Mvp\Documents\Domain\Enums;

enum SendStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Non scaricato',
            self::Sent => 'Scaricato',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Sent => 'success',
        };
    }
}
