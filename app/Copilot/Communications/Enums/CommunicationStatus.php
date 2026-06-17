<?php

namespace App\Copilot\Communications\Enums;

enum CommunicationStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Approved => 'Approvata',
            self::Discarded => 'Scartata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Approved => 'success',
            self::Discarded => 'gray',
        };
    }
}
