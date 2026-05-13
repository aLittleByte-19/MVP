<?php

namespace App\Poc\Enums;

/**
 * Enumeration of document processing statuses.
 */
enum ProcessingStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Get the human-readable label for the status.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In attesa',
            self::Processing => 'In elaborazione',
            self::Completed => 'Completato',
            self::Failed => 'Fallito',
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
            self::Pending => 'gray',
            self::Processing => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
