<?php

namespace App\Mvp\Communications\Enums;

enum CoverImageSource: string
{
    case Ai = 'ai';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Ai => 'Generata dall\'AI',
            self::Manual => 'Caricata manualmente',
        };
    }
}
