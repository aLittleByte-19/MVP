<?php

namespace App\Models\Copilot;

use App\Copilot\Communications\Enums\SendStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $original_document_id
 * @property string $file_path
 * @property int $start_page
 * @property int $end_page
 * @property SendStatus $send_status
 * @property string|null $error_message
 * @property OriginalDocument|null $originalDocument
 * @property ExtractedData|null $extractedData
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
        'error_message',
    ];

    /**
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
     * @return BelongsTo<OriginalDocument, $this>
     */
    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(OriginalDocument::class);
    }

    /**
     * @return HasOne<ExtractedData, $this>
     */
    public function extractedData(): HasOne
    {
        return $this->hasOne(ExtractedData::class);
    }
}
