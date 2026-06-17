<?php

namespace App\Models\Copilot;

use App\Copilot\Communications\Enums\CommunicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string|null $created_by
 * @property string $prompt
 * @property string $tone
 * @property string $style
 * @property string|null $generated_title
 * @property string|null $generated_body
 * @property CommunicationStatus $status
 * @property Carbon|null $created_at
 */
class Communication extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'created_by',
        'prompt',
        'tone',
        'style',
        'generated_title',
        'generated_body',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CommunicationStatus::class,
        ];
    }
}
