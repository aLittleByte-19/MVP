<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\Exceptions\CommunicationNotReadyForExportException;
use App\Mvp\Communications\Domain\ValueObjects\RenderedCommunicationPdf;

/**
 * Porta primaria: documento finale impaginato (UC-10 anteprima, UC-11
 * esportazione). `fingerprint()` e' separato da `render()` perche' l'adapter
 * HTTP deve poter rispondere 304 senza renderizzare nulla — resta comunque
 * l'adapter a chiamare solo questa porta primaria, mai il renderer (porta
 * secondaria) direttamente.
 */
interface ExportCommunicationUseCase
{
    /**
     * @throws CommunicationNotReadyForExportException
     */
    public function fingerprint(int $communicationId): string;

    /**
     * @throws CommunicationNotReadyForExportException
     */
    public function render(int $communicationId): RenderedCommunicationPdf;
}
