<?php

namespace App\Poc\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model representing data extracted from a document by AI.
 */
class ExtractedData extends Model
{
    use HasFactory;

    protected $fillable = [
        'sub_document_id',
        'employee_first_name',
        'employee_last_name',
        'company_name',
        'document_date',
        'document_type',
        'description',
        'confidence_score',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'confidence_score' => 'integer',
        ];
    }

    /**
     * Get the sub-document that owns the extracted data.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function subDocument(): BelongsTo
    {
        return $this->belongsTo(SubDocument::class);
    }
}
