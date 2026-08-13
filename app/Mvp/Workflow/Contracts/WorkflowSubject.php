<?php

namespace App\Mvp\Workflow\Contracts;

/**
 * Cio' che WorkflowTaskRunner (domain-agnostic, condiviso da tutti i domini)
 * ha bisogno di sapere sull'aggregato risolto da un handler: id e tenant, per
 * il claim/dedup e l'audit. Sostituisce Illuminate\Database\Eloquent\Model —
 * il runner non deve conoscere Eloquent, solo questa forma generica. Ogni
 * handler risolve comunque l'aggregato reale (tramite il proprio repository
 * di dominio) per il lavoro di business vero e proprio dentro execute().
 */
final class WorkflowSubject
{
    public function __construct(
        public readonly int $id,
        public readonly string $tenantId,
    ) {}
}
