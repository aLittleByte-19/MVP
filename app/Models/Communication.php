<?php

namespace App\Models;

use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Communications\Enums\CoverImageSource;
use App\Mvp\Communications\Enums\CoverImageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $tenant_id
 * @property string|null $created_by
 * @property string $prompt
 * @property string $tone
 * @property string $style
 * @property string|null $generated_title
 * @property string|null $generated_body
 * @property string|null $image_prompt
 * @property CommunicationGenerationStatus $generation_status
 * @property string|null $workflow_execution_arn
 * @property Carbon|null $workflow_started_at
 * @property Carbon|null $workflow_completed_at
 * @property Carbon|null $workflow_failed_at
 * @property string|null $workflow_failure_reason
 * @property string|null $error_message
 * @property string|null $cover_image_path
 * @property string|null $cover_image_mime
 * @property int|null $cover_image_size
 * @property CoverImageSource|null $cover_image_source
 * @property CoverImageStatus $cover_status
 * @property string|null $cover_error
 * @property CommunicationStatus $status
 * @property bool $is_favorite
 * @property int|null $rating
 * @property string|null $rating_comment
 * @property Carbon|null $rated_at
 * @property string|null $rated_by
 * @property Carbon|null $created_at
 */
class Communication extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'created_by',
        'prompt',
        'tone',
        'style',
        'generated_title',
        'generated_body',
        'image_prompt',
        'generation_status',
        'workflow_execution_arn',
        'workflow_started_at',
        'workflow_completed_at',
        'workflow_failed_at',
        'workflow_failure_reason',
        'error_message',
        'cover_image_path',
        'cover_image_mime',
        'cover_image_size',
        'cover_image_source',
        'cover_status',
        'cover_error',
        'status',
        'is_favorite',
        'rating',
        'rating_comment',
        'rated_at',
        'rated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CommunicationStatus::class,
            'is_favorite' => 'boolean',
            'generation_status' => CommunicationGenerationStatus::class,
            'cover_image_source' => CoverImageSource::class,
            'cover_status' => CoverImageStatus::class,
            'workflow_started_at' => 'datetime',
            'workflow_completed_at' => 'datetime',
            'workflow_failed_at' => 'datetime',
            'cover_image_size' => 'integer',
            'rating' => 'integer',
            'rated_at' => 'datetime',
        ];
    }

    /**
     * @return MorphMany<WorkflowTask, $this>
     */
    public function workflowTasks(): MorphMany
    {
        return $this->morphMany(WorkflowTask::class, 'subject');
    }
}
