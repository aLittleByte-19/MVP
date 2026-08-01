<?php

namespace App\Mvp\Audit\Services;

use App\Models\AuditEvent;
use App\Mvp\Identity\MvpUser;
use App\Mvp\Workflow\Support\WorkflowContext;
use Illuminate\Http\Request;

class AuditLogger
{
    public function __construct(
        private readonly WorkflowContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $eventType,
        ?MvpUser $actor = null,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $metadata = [],
        ?Request $request = null,
        ?string $tenantId = null,
    ): AuditEvent {
        return AuditEvent::create([
            'event_type' => $eventType,
            'tenant_id' => $actor?->tenantId ?? $tenantId ?? $this->context->tenantId(),
            'actor_id' => $actor?->id,
            'actor_email' => $actor?->email,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            // Fuori da una request HTTP gli id arrivano dal messaggio SQS: senza
            // questo fallback gli eventi scritti dal worker non sarebbero
            // correlabili alla richiesta che ha avviato l'esecuzione.
            'request_id' => $request?->attributes->get('request_id') ?? $this->context->requestId(),
            'correlation_id' => $request?->attributes->get('correlation_id') ?? $this->context->correlationId(),
            'metadata' => $metadata,
        ]);
    }
}
