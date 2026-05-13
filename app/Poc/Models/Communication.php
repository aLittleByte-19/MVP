<?php

namespace App\Poc\Models;

use App\Poc\Enums\CommunicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model representing a generated communication.
 */
class Communication extends Model
{
    use HasFactory;

    protected $fillable = [
        'prompt',
        'tone',
        'style',
        'generated_title',
        'generated_body',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CommunicationStatus::class,
        ];
    }
}
