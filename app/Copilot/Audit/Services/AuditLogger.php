<?php

namespace App\Copilot\Audit\Services;

use App\Copilot\Identity\PocUser;
use App\Models\Copilot\AuditEvent;
use Illuminate\Http\Request;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $eventType,
        ?PocUser $actor = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = [],
        ?Request $request = null,
        ?string $tenantId = null,
    ): AuditEvent {
        return AuditEvent::create([
            'event_type' => $eventType,
            'tenant_id' => $actor?->tenantId ?? $tenantId,
            'actor_id' => $actor?->id,
            'actor_email' => $actor?->email,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'request_id' => $request?->attributes->get('request_id'),
            'correlation_id' => $request?->attributes->get('correlation_id'),
            'metadata' => $metadata,
        ]);
    }
}
