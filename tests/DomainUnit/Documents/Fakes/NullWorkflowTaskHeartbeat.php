<?php

namespace Tests\DomainUnit\Documents\Fakes;

use App\Mvp\Workflow\Services\WorkflowTaskHeartbeat;

/**
 * WorkflowTaskHeartbeat e' infrastruttura di Workflow condivisa (fuori dal
 * perimetro esagonale, vedi ADR 0010): il suo costruttore richiede un vero
 * SfnClient. extractAndSaveFields() non lo invoca mai (solo process(), che
 * gestisce l'intera pipeline PDF e non e' testabile in isolamento per lo
 * stesso motivo — storage_path()), quindi qui basta un doppio inerte che non
 * costruisce nessuna dipendenza AWS.
 */
final class NullWorkflowTaskHeartbeat extends WorkflowTaskHeartbeat
{
    public function __construct() {}

    public function beat(bool $force = false): void {}

    public function activate(string $taskToken, string $taskType): void {}

    public function deactivate(): void {}
}
