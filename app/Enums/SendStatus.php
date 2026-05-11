<?php

namespace App\Enums;

enum SendStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Da inviare',
            self::Sent => 'Inviato',
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
