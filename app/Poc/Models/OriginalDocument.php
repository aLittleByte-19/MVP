<?php

namespace App\Poc\Models;

use App\Poc\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model representing an original uploaded document.
 */
class OriginalDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'file_path',
        'original_filename',
        'processing_status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processing_status' => ProcessingStatus::class,
        ];
    }

    /**
     * Get the sub-documents associated with this original document.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function subDocuments(): HasMany
    {
        return $this->hasMany(SubDocument::class);
    }
}
