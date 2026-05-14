<?php

namespace App\Poc\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'confidence_score' => 'integer',
        ];
    }

    public function subDocument(): BelongsTo
    {
        return $this->belongsTo(SubDocument::class);
    }
}
