<?php

namespace App\Enums;

enum CommunicationStatus: string
{
    case Draft = 'draft';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Discarded => 'Scartata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Discarded => 'gray',
        };
    }
}
