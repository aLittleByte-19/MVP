<?php

namespace App\Poc\Enums;

/**
 * Enumeration of communication send statuses.
 */
enum SendStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';

    /**
     * Get the human-readable label for the status.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Da inviare',
            self::Sent => 'Inviato',
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
            self::Pending => 'warning',
            self::Sent => 'success',
        };
    }
}
