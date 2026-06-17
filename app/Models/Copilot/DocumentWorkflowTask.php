<?php

namespace App\Models\Copilot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $original_document_id
 * @property string $task_type
 * @property string $task_token_hash
 * @property string $status
 * @property array<string, mixed>|null $input_payload
 * @property array<string, mixed>|null $output_payload
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property OriginalDocument|null $originalDocument
 */
class DocumentWorkflowTask extends Model
{
    protected $fillable = [
        'original_document_id',
        'task_type',
        'task_token_hash',
        'status',
        'input_payload',
        'output_payload',
        'error_message',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'output_payload' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OriginalDocument, $this>
     */
    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(OriginalDocument::class);
    }
}
