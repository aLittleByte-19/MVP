<?php

namespace App\Mvp\Support;

use Dompdf\Dompdf;

/**
 * Piede di pagina condiviso da tutti i PDF generati dal sistema: marchio a
 * sinistra, modulo che ha prodotto il documento al centro, numero di pagina a
 * destra. I colori sono quelli del design system del sito (tokens.css:
 * --mvp-primary-deep, --mvp-border, --mvp-muted).
 *
 * La dicitura centrale e' il marcatore di trasparenza, e sta su ogni pagina.
 * Le parole sono quelle del Capitolato (UC-5 dell'AI Assistant: «tracciato come
 * "Creato da AI Assistant"») e del glossario dell'Analisi dei Requisiti, che la
 * definisce come «dicitura inserita nei contenuti esportati»: nessuno dei due
 * ne prescrive la forma grafica, ma il testo e' quello e va lasciato cosi'.
 *
 * dompdf non renderizza i margin-box CSS3 (@page { @bottom-center }): i
 * segnaposto {PAGE_NUM}/{PAGE_COUNT} di page_text() sono l'unico modo
 * affidabile per numerare le pagine, quindi tutto il piede passa dall'API
 * canvas invece che dall'HTML.
 */
class PdfFooterStamper
{
    /**
     * @param  string  $origin  Il modulo che ha prodotto il documento.
     * @param  int|null  $totalPages  Il totale del file finale, quando al
     *                                messaggio verranno accodate altre pagine:
     *                                senza, il conteggio si fermerebbe alle
     *                                pagine che dompdf conosce e direbbe «1 di
     *                                1» su un file che ne ha tre.
     */
    public function stamp(Dompdf $dompdf, string $origin, ?int $totalPages = null): void
    {
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $marginX = 60;
        $ruleY = $canvas->get_height() - 55;
        $textY = $canvas->get_height() - 40;

        $canvas->line($marginX, $ruleY, $canvas->get_width() - $marginX, $ruleY, [0.843, 0.894, 0.937], 0.75);

        $brandFont = $fontMetrics->getFont('helvetica', 'bold');
        $brandSize = 9;
        $canvas->page_text($marginX, $textY, 'NEXUM', $brandFont, $brandSize, [0.141, 0.318, 0.439], 0.0, 1.2);

        $originFont = $fontMetrics->getFont('helvetica');
        $originSize = 8.5;
        $originText = "Creato da {$origin}";
        $originWidth = $fontMetrics->getTextWidth($originText, $originFont, $originSize);
        $canvas->page_text(
            ($canvas->get_width() - $originWidth) / 2,
            $textY,
            $originText,
            $originFont,
            $originSize,
            [0.561, 0.651, 0.729],
        );

        $pageFont = $fontMetrics->getFont('helvetica');
        $pageSize = 8.5;
        $pageText = 'Pagina {PAGE_NUM} di '.($totalPages !== null ? (string) $totalPages : '{PAGE_COUNT}');
        $pageMarginX = 40;
        $pageTextWidth = $fontMetrics->getTextWidth($pageText, $pageFont, $pageSize);
        $canvas->page_text($canvas->get_width() - $pageMarginX - $pageTextWidth, $textY, $pageText, $pageFont, $pageSize, [0.337, 0.435, 0.522]);
    }
}
