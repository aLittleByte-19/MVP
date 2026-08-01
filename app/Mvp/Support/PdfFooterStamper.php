<?php

namespace App\Mvp\Support;

use Dompdf\Dompdf;

/**
 * Marcatore NEXUM + numero di pagina condiviso da tutti i PDF generati dal
 * sistema (comunicazioni AI Assistant, messaggi di invio Co-Pilot), con i
 * colori del design system del sito (tokens.css: --mvp-primary-deep,
 * --mvp-border, --mvp-muted). dompdf non renderizza i margin-box CSS3
 * (@page { @bottom-center }): i segnaposto {PAGE_NUM}/{PAGE_COUNT} di
 * page_text() sono l'unico modo affidabile per numerare le pagine, quindi
 * tutto il piè di pagina passa dall'API canvas invece che dall'HTML.
 */
class PdfFooterStamper
{
    public function stamp(Dompdf $dompdf): void
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

        $pageFont = $fontMetrics->getFont('helvetica');
        $pageSize = 8.5;
        $pageText = 'Pagina {PAGE_NUM} di {PAGE_COUNT}';
        $pageMarginX = 40;
        $pageTextWidth = $fontMetrics->getTextWidth($pageText, $pageFont, $pageSize);
        $canvas->page_text($canvas->get_width() - $pageMarginX - $pageTextWidth, $textY, $pageText, $pageFont, $pageSize, [0.337, 0.435, 0.522]);
    }
}
