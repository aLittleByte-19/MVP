<?php

namespace App\Poc\Models;

use App\Poc\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OriginalDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_path',
        'original_filename',
        'processing_status',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processing_status' => ProcessingStatus::class,
        ];
    }

    public function subDocuments(): HasMany
    {
        return $this->hasMany(SubDocument::class);
    }
}
