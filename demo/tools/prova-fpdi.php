<?php

use setasign\Fpdi\Fpdi;

/**
 * Verifica che FPDI sappia aprire e tagliare i PDF generati.
 *
 * Riproduce le stesse chiamate di ProcessDocumentService::extractPages: se il
 * parser libero di FPDI non regge la compressione del file, fallisce qui
 * invece che a meta' del popolamento.
 */

require __DIR__.'/../../vendor/autoload.php';

$esito = 0;

$cartelle = $argv[1] ?? __DIR__.'/../pdf/{dataset,live}/*.pdf';

foreach (glob($cartelle, GLOB_BRACE) as $file) {
    $nome = basename($file);

    try {
        $pdf = new Fpdi;
        $pageCount = $pdf->setSourceFile($file);

        // Taglia la sola prima pagina, come farebbe un segmento di una pagina.
        $tplIdx = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($tplIdx);
        $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
        $pdf->useTemplate($tplIdx);

        $destinazione = sys_get_temp_dir().'/prova_'.$nome;
        $pdf->Output($destinazione, 'F');
        $tagliato = filesize($destinazione);
        @unlink($destinazione);

        printf("  OK   %-58s %d pagine, taglio da %.1f KB\n", $nome, $pageCount, $tagliato / 1024);
    } catch (Throwable $e) {
        printf("  FAIL %-58s %s: %s\n", $nome, get_class($e), $e->getMessage());
        $esito = 1;
    }
}

exit($esito);
