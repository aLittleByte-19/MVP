<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

/**
 * Porta primaria: passi finali della pipeline documentale ("persist.results",
 * "dispatch.domain_event"). Sono passi deliberatamente minimi (nessuna
 * decisione di dominio oltre "e' completato?"): restano una porta primaria
 * a se stante — non un accesso diretto al repository dall'adapter — perche'
 * un adapter primario chiama sempre un caso d'uso, mai una porta secondaria
 * direttamente (vedi ADR 0010).
 */
interface FinalizeDocumentWorkflowUseCase
{
    public function currentStatus(int $documentId): string;

    /**
     * @return array{event: string}
     */
    public function dispatchCompletionEvent(int $documentId): array;
}
