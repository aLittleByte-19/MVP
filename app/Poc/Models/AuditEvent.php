<?php

namespace App\Poc\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_type',
        'tenant_id',
        'actor_id',
        'actor_email',
        'resource_type',
        'resource_id',
        'request_id',
        'correlation_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
