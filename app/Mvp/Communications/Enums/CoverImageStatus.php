<?php

namespace App\Mvp\Communications\Enums;

enum CoverImageStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    // Rimozione voluta dall'operatore: esito neutro, non un degrado da segnalare.
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In coda',
            self::Processing => 'In generazione',
            self::Ready => 'Disponibile',
            self::Failed => 'Non disponibile',
            self::Removed => 'Rimossa',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Processing => 'warning',
            self::Ready => 'success',
            self::Failed => 'danger',
            self::Removed => 'gray',
        };
    }
}
