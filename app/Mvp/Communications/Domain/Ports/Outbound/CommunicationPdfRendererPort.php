<?php

namespace App\Mvp\Communications\Domain\Ports\Outbound;

use App\Mvp\Communications\Domain\ValueObjects\CommunicationPdfContext;

/**
 * Porta secondaria: rende in PDF il documento finale di una comunicazione
 * (anteprima UC-10 ed esportazione UC-11). Nessun riferimento a Dompdf.
 */
interface CommunicationPdfRendererPort
{
    /**
     * Impronta del contenuto renderizzato, usata come ETag: stessa impronta
     * => stesso PDF byte per byte. Esposta a se stante (non solo dentro
     * render()) perche' il chiamante deve poter rispondere 304 senza
     * renderizzare nulla.
     */
    public function fingerprint(CommunicationPdfContext $context): string;

    public function render(CommunicationPdfContext $context): string;

    public function filename(CommunicationPdfContext $context): string;
}
