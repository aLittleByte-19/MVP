<?php

namespace App\Models;

use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Enums\SendStatus;
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
 * @property ReviewStatus $review_status
 * @property string|null $error_message
 * @property string|null $send_recipient_override
 * @property string|null $send_subject_override
 * @property string|null $send_body_override
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
        'review_status',
        'error_message',
        'send_recipient_override',
        'send_subject_override',
        'send_body_override',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'send_status' => SendStatus::class,
            'review_status' => ReviewStatus::class,
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
