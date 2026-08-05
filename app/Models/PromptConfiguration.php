<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string|null $created_by
 * @property string $name
 * @property string $prompt
 * @property string $tone
 * @property string $style
 * @property Carbon|null $created_at
 */
class PromptConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'prompt',
        'tone',
        'style',
    ];
}
