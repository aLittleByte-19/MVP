<?php

namespace App\Copilot\Documents\Enums;

enum ProcessingStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In attesa',
            self::Processing => 'In elaborazione',
            self::Completed => 'Completato',
            self::Failed => 'Fallito',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
