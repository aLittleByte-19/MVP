<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

/**
 * Porta primaria: avvia la pipeline Step Functions per un documento
 * originale gia' persistito. Separata da UploadDocumentUseCase (che si limita
 * a salvare il file e creare il record): due decisioni distinte invocate in
 * sequenza dallo stesso adapter HTTP, non un'unica porta — cosi' l'avvio
 * pipeline resta testabile in isolamento su un documento gia' esistente.
 */
interface StartDocumentWorkflowUseCase
{
    public function start(int $documentId, ?string $correlationId, ?string $requestId): void;
}
