<?php

namespace App\Poc\Models;

use App\Poc\Enums\SendStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Model representing a part of an original document (e.g., a single page).
 */
class SubDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'original_document_id',
        'file_path',
        'start_page',
        'end_page',
        'send_status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'send_status' => SendStatus::class,
            'start_page' => 'integer',
            'end_page' => 'integer',
        ];
    }

    /**
     * Get the original document that this sub-document belongs to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(OriginalDocument::class);
    }

    /**
     * Get the extracted data associated with this sub-document.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function extractedData(): HasOne
    {
        return $this->hasOne(ExtractedData::class);
    }
}
