<?php

namespace App\Mvp\Documents\Domain\Ports\Outbound;

use App\Mvp\Documents\Domain\ValueObjects\SendMessageComposition;

/**
 * Porta secondaria: rende in PDF il messaggio di invio precompilato. Nessun
 * riferimento a Dompdf o ai template Blade: l'adapter decide come renderizzare.
 */
interface SendMessageRendererPort
{
    public function renderPdf(SendMessageComposition $composition): string;
}
