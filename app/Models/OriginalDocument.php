<?php

namespace App\Models;

use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string|null $created_by
 * @property string $file_path
 * @property string $original_filename
 * @property string|null $manual_document_type
 * @property string|null $manual_company_name
 * @property int|null $manual_reference_month
 * @property int|null $manual_reference_year
 * @property ProcessingStatus $processing_status
 * @property string|null $error_message
 * @property string|null $s3_bucket
 * @property string|null $s3_key
 * @property string|null $workflow_execution_arn
 * @property Carbon|null $workflow_started_at
 * @property Carbon|null $workflow_completed_at
 * @property Carbon|null $workflow_failed_at
 * @property string|null $workflow_failure_reason
 * @property string|null $textract_job_id
 * @property string|null $ocr_text
 * @property array<int, array{page: int, text: string, confidence_avg: float|null}>|null $ocr_pages
 * @property float|null $ocr_confidence_avg
 */
class OriginalDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'created_by',
        'file_path',
        'original_filename',
        'manual_document_type',
        'manual_company_name',
        'manual_reference_month',
        'manual_reference_year',
        'processing_status',
        'error_message',
        's3_bucket',
        's3_key',
        'workflow_execution_arn',
        'workflow_started_at',
        'workflow_completed_at',
        'workflow_failed_at',
        'workflow_failure_reason',
        'textract_job_id',
        'ocr_text',
        'ocr_pages',
        'ocr_confidence_avg',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processing_status' => ProcessingStatus::class,
            'manual_reference_month' => 'integer',
            'manual_reference_year' => 'integer',
            'workflow_started_at' => 'datetime',
            'workflow_completed_at' => 'datetime',
            'workflow_failed_at' => 'datetime',
            'ocr_confidence_avg' => 'float',
            'ocr_pages' => 'array',
        ];
    }

    /**
     * True se almeno un metadato manuale e' stato impostato in fase di upload.
     */
    public function hasManualUploadMetadata(): bool
    {
        return $this->manual_document_type !== null
            || $this->manual_company_name !== null
            || $this->manual_reference_month !== null
            || $this->manual_reference_year !== null;
    }

    /**
     * @return HasMany<SubDocument, $this>
     */
    public function subDocuments(): HasMany
    {
        return $this->hasMany(SubDocument::class);
    }

    /**
     * @return MorphMany<WorkflowTask, $this>
     */
    public function workflowTasks(): MorphMany
    {
        return $this->morphMany(WorkflowTask::class, 'subject');
    }
}
