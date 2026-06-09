<?php

namespace App\Models\Copilot;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sub_document_id
 * @property string|null $employee_first_name
 * @property string|null $employee_last_name
 * @property string|null $company_name
 * @property Carbon|null $document_date
 * @property string|null $document_type
 * @property string|null $description
 * @property int|null $confidence_score
 * @property SubDocument|null $subDocument
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
     * @return BelongsTo<SubDocument, $this>
     */
    public function subDocument(): BelongsTo
    {
        return $this->belongsTo(SubDocument::class);
    }
}
