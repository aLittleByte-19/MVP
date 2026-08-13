<?php

namespace App\Mvp\Communications\Domain\Enums;

/**
 * Technical lifecycle of the generation pipeline, kept separate from
 * CommunicationStatus, which records the operator decision on the draft.
 */
enum CommunicationGenerationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In coda',
            self::Processing => 'In generazione',
            self::Completed => 'Generata',
            self::Failed => 'Non generata',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }
}
