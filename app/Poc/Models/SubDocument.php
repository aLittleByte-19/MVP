<?php

namespace App\Poc\Models;

use App\Poc\Enums\SendStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    protected function casts(): array
    {
        return [
            'send_status' => SendStatus::class,
            'start_page' => 'integer',
            'end_page' => 'integer',
        ];
    }

    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(OriginalDocument::class);
    }

    public function extractedData(): HasOne
    {
        return $this->hasOne(ExtractedData::class);
    }
}
