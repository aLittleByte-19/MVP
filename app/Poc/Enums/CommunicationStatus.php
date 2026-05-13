<?php

namespace App\Poc\Enums;

/**
 * Enumeration of communication statuses.
 */
enum CommunicationStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Discarded = 'discarded';

    /**
     * Get the human-readable label for the status.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Approved => 'Approvata',
            self::Discarded => 'Scartata',
        };
    }

    /**
     * Get the color associated with the status.
     *
     * @return string
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Approved => 'success',
            self::Discarded => 'gray',
        };
    }
}
