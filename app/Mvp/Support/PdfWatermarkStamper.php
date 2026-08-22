<?php

namespace App\Mvp\Support;

use Dompdf\Dompdf;

/**
 * La filigrana diagonale dei PDF generati dal sistema.
 *
 * `page_text` ruota il testo attorno al proprio punto di ancoraggio e non
 * attorno al centro della riga: ancorata a meta' pagina, la diagonale partiva
 * dal centro del foglio e usciva dal bordo in basso a destra. Il punto di
 * partenza va quindi arretrato di meta' della diagonale che il testo occupera',
 * su entrambi gli assi.
 *
 * Il tono e' quello delle superfici tenui del design system (tokens.css,
 * --mvp-border-soft): una filigrana si deve leggere quando la si cerca e
 * sparire mentre si legge il testo che le sta sopra.
 */
class PdfWatermarkStamper
{
    private const SIZE = 30;

    private const CHAR_SPACING = 2.0;

    /**
     * Angolo negativo: il testo sale da sinistra verso destra, come una
     * filigrana si aspetta di essere. Con l'angolo positivo dompdf la fa
     * scendere, e la scritta sembrava cadere in un angolo del foglio.
     */
    private const ANGLE = -45.0;

    private const COLOR = [0.894, 0.929, 0.961];

    public function stamp(Dompdf $dompdf, string $text): void
    {
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('helvetica', 'bold');

        $width = $fontMetrics->getTextWidth($text, $font, self::SIZE)
            + self::CHAR_SPACING * max(0, mb_strlen($text) - 1);

        // Il punto di ancoraggio e' l'inizio della riga, e la riga ruota
        // attorno a quello: per centrare la diagonale sul foglio va arretrato
        // di meta' della proiezione del testo su entrambi gli assi.
        $radians = deg2rad(abs(self::ANGLE));
        $x = ($canvas->get_width() - $width * cos($radians)) / 2;
        $y = ($canvas->get_height() + $width * sin($radians)) / 2;

        $canvas->page_text($x, $y, $text, $font, self::SIZE, self::COLOR, 0.0, self::CHAR_SPACING, self::ANGLE);
    }
}
