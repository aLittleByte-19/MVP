<?php

namespace App\Mvp\Documents\Domain\Ports\Outbound;

use App\Mvp\Documents\Domain\ValueObjects\SendMessageComposition;

/**
 * Porta secondaria: rende in PDF il messaggio di invio precompilato. Nessun
 * riferimento a Dompdf o ai template Blade: l'adapter decide come renderizzare.
 *
 * `$attachmentPdf` sono i byte del sotto-documento, che l'adapter accoda al
 * messaggio: la riga «in allegato trova il documento» prometteva un allegato
 * che nel file scaricato non c'era, e l'operatore doveva spedire i due PDF a
 * mano. Null quando il documento non serve, come nell'anteprima.
 */
interface SendMessageRendererPort
{
    public function renderPdf(SendMessageComposition $composition, ?string $attachmentPdf = null): string;
}
