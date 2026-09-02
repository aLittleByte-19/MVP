<?php

/**
 * Utilita' condivise dai generatori dei documenti di prova.
 */

/**
 * Completa un codice fiscale calcolandone il carattere di controllo.
 *
 * I quindici caratteri in ingresso sono verosimili ma inventati: il carattere
 * finale va calcolato con lo stesso algoritmo di CodiceFiscale::isValid,
 * altrimenti il caso d'uso scarta il valore e il campo resta vuoto nel
 * documento estratto.
 */
function codiceFiscaleCompleto(string $primi15): string
{
    $odd = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9,
        '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9,
        'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11,
        'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    $even = [
        '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4,
        '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9,
        'A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4,
        'F' => 5, 'G' => 6, 'H' => 7, 'I' => 8, 'J' => 9,
        'K' => 10, 'L' => 11, 'M' => 12, 'N' => 13, 'O' => 14,
        'P' => 15, 'Q' => 16, 'R' => 17, 'S' => 18, 'T' => 19,
        'U' => 20, 'V' => 21, 'W' => 22, 'X' => 23, 'Y' => 24, 'Z' => 25,
    ];

    $code = strtoupper($primi15);

    if (! preg_match('/^[A-Z0-9]{15}$/', $code)) {
        throw new InvalidArgumentException("Servono 15 caratteri alfanumerici, ricevuto: {$primi15}");
    }

    $sum = 0;
    for ($position = 0; $position < 15; $position++) {
        $sum += $position % 2 === 0 ? $odd[$code[$position]] : $even[$code[$position]];
    }

    return $code.chr(65 + ($sum % 26));
}

/**
 * Rende in PDF un documento HTML con dompdf e lo scrive su disco.
 */
function rendiPdf(string $html, string $css, string $destinazione): void
{
    $dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
    $dompdf->loadHtml("<html><head><meta charset=\"utf-8\"><style>{$css}</style></head><body>{$html}</body></html>");
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $directory = dirname($destinazione);
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($destinazione, $dompdf->output());
}
