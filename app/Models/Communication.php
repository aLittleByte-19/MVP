<?php

namespace App\Models;

use App\Enums\CommunicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    protected function casts(): array
    {
        return [
            'status' => CommunicationStatus::class,
        ];
    }
}
