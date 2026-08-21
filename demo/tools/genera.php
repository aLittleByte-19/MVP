<?php

/**
 * Genera i venticinque PDF del set di prova.
 *
 *   demo/pdf/dataset/  venti documenti, che finiscono nello snapshot
 *   demo/pdf/live/     cinque documenti, tenuti da parte per la demo dal vivo
 *
 * Gli importi sono costruiti in modo deterministico dal nome della persona:
 * rigenerare il set produce gli stessi documenti, e un confronto fra due
 * esecuzioni mostra solo cio' che e' stato cambiato davvero.
 *
 * Si esegue dal container dell'applicativo, che ha dompdf fra le dipendenze:
 *   docker compose run --rm --no-deps -v "$PWD/demo:/var/www/html/demo" \
 *     app php demo/tools/genera.php
 */

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/support.php';
require __DIR__.'/anagrafica.php';
require __DIR__.'/templates.php';

/** Numero stabile ricavato da una stringa: stessi dati a ogni esecuzione. */
function semeDa(string $chiave, int $minimo, int $massimo): int
{
    $valore = hexdec(substr(md5($chiave), 0, 6));

    return $minimo + (int) ($valore % (($massimo - $minimo) + 1));
}

function euro(float $valore): string
{
    return number_format($valore, 2, ',', '.');
}

/** Dati retributivi verosimili e stabili per una persona in un dato mese. */
function datiCedolino(array $persona, string $periodo, ?string $emissione): array
{
    $chiave = $persona['cf'].$periodo;
    $oraria = semeDa($chiave.'oraria', 1050, 1750) / 100;
    $ordinarie = 168.0;
    $base = $oraria * $ordinarie;
    $straordinario = semeDa($chiave.'stra', 0, 14) * 1.0;
    $importoStraordinario = $straordinario * $oraria * 1.25;
    $indennita = semeDa($chiave.'ind', 0, 4) * 55.0;
    $competenze = $base + $importoStraordinario + $indennita;
    $inps = $competenze * 0.0919;
    $irpef = ($competenze - $inps) * semeDa($chiave.'irpef', 19, 27) / 100;
    $ritenute = $inps + $irpef;

    $voci = [
        ['Retribuzione ordinaria', number_format($ordinarie, 2, ',', '.').' ore', euro($oraria), euro($base)],
    ];

    if ($straordinario > 0) {
        $voci[] = ['Straordinario diurno 25%', number_format($straordinario, 2, ',', '.').' ore', euro($oraria * 1.25), euro($importoStraordinario)];
    }

    if ($indennita > 0) {
        $voci[] = ['Indennita\' di funzione', '1,00', euro($indennita), euro($indennita)];
    }

    $voci[] = ['Contributi INPS a carico dipendente', '', '9,19%', '-'.euro($inps)];
    $voci[] = ['Ritenuta IRPEF lorda', '', '', '-'.euro($irpef)];
    $voci[] = ['Detrazioni lavoro dipendente', '', '', euro(semeDa($chiave.'detr', 40, 110))];

    return [
        'periodo' => $periodo,
        'emissione' => $emissione,
        'voci' => $voci,
        'competenze' => euro($competenze),
        'ritenute' => euro($ritenute),
        'netto' => euro($competenze - $ritenute),
        'progrPrevidenziale' => euro($competenze * semeDa($chiave.'pp', 3, 11)),
        'progrFiscale' => euro($competenze * semeDa($chiave.'pf', 3, 11) * 0.92),
        'progrIrpef' => euro($irpef * semeDa($chiave.'pi', 3, 11)),
        'ferieResidue' => semeDa($chiave.'fer', 12, 148),
    ];
}

function datiCertificazione(array $persona, string $anno, ?string $emissione): array
{
    $chiave = $persona['cf'].$anno;
    $redditi = semeDa($chiave.'red', 21000, 48000);
    $irpef = $redditi * semeDa($chiave.'irp', 19, 28) / 100;

    return [
        'periodo' => $anno,
        'emissione' => $emissione,
        'redditi' => euro($redditi),
        'irpef' => euro($irpef),
        'regionale' => euro($redditi * 0.0173),
        'comunale' => euro($redditi * 0.008),
        'giorni' => (string) semeDa($chiave.'gg', 300, 365),
        'imponibile' => euro($redditi * 1.04),
        'contributi' => euro($redditi * 0.0919),
        'settimane' => (string) semeDa($chiave.'set', 44, 52),
        'sedeInps' => (string) semeDa($chiave.'inps', 4100, 8900),
    ];
}

function datiFerie(array $persona, string $periodo, ?string $emissione): array
{
    $chiave = $persona['cf'].$periodo;
    $maturate = semeDa($chiave.'mat', 120, 208);
    $godute = semeDa($chiave.'god', 24, 110);
    $causali = ['Ferie', 'Permesso retribuito', 'ROL', 'Ferie', 'Permesso ex festivita\''];
    $movimenti = [];
    $residuo = $maturate;

    for ($i = 0; $i < 5; $i++) {
        $ore = semeDa($chiave.'ore'.$i, 4, 16);
        $residuo -= $ore;
        $giorno = semeDa($chiave.'gio'.$i, 1, 28);
        $mese = semeDa($chiave.'mes'.$i, 1, 12);
        $movimenti[] = [
            sprintf('%02d/%02d/2026', $giorno, $mese),
            $causali[$i],
            number_format($ore, 2, ',', '.'),
            number_format(max(0, $residuo), 2, ',', '.'),
        ];
    }

    return [
        'periodo' => $periodo,
        'emissione' => $emissione,
        'movimenti' => $movimenti,
        'maturate' => number_format($maturate, 2, ',', '.'),
        'godute' => number_format($godute, 2, ',', '.'),
        'residuo' => number_format(max(0, $maturate - $godute), 2, ',', '.'),
    ];
}

/** @return array<string, array{oggetto: string, paragrafi: list<string>}> */
function corpiLettera(): array
{
    return [
        'premio' => [
            'oggetto' => 'Assegnazione del premio di risultato 2026',
            'paragrafi' => [
                'Con la presente Le comunichiamo che, a seguito della verifica degli obiettivi definiti in sede di contrattazione aziendale, Le e\' stato riconosciuto il premio di risultato per l\'anno in corso.',
                'L\'importo sara\' corrisposto con la retribuzione del mese successivo alla data della presente comunicazione, con l\'applicazione dell\'imposta sostitutiva del 5% ove ne ricorrano i presupposti di legge.',
                'La invitiamo a verificare i dati riportati nel cedolino e a segnalare tempestivamente all\'ufficio del personale eventuali difformita\'.',
            ],
        ],
        'assunzione' => [
            'oggetto' => 'Comunicazione di assunzione e consegna documentazione',
            'paragrafi' => [
                'Le confermiamo l\'avvenuta assunzione presso la nostra azienda con decorrenza dalla data indicata nel contratto individuale di lavoro sottoscritto fra le parti.',
                'Le ricordiamo che la documentazione relativa al rapporto di lavoro, compresi i prospetti paga mensili, sara\' resa disponibile esclusivamente in formato elettronico all\'indirizzo di posta indicato.',
                'Per ogni necessita\' relativa all\'inquadramento contrattuale e alle condizioni economiche puo\' rivolgersi all\'ufficio del personale negli orari di apertura.',
            ],
        ],
        'tfr' => [
            'oggetto' => 'Comunicazione annuale sul trattamento di fine rapporto',
            'paragrafi' => [
                'Come previsto dalla normativa vigente, Le trasmettiamo il riepilogo del trattamento di fine rapporto accantonato a Suo favore alla chiusura dell\'esercizio.',
                'La quota maturata nel periodo e\' stata rivalutata secondo l\'indice ISTAT dei prezzi al consumo, applicando il coefficiente previsto dall\'articolo 2120 del codice civile.',
                'La presente comunicazione ha valore informativo e non sostituisce la certificazione rilasciata al momento della cessazione del rapporto di lavoro.',
            ],
        ],
    ];
}

/**
 * Compone un documento a partire dalla sua specifica.
 *
 * @param  array<string, mixed>  $spec
 */
function componiDocumento(array $spec): string
{
    $aziende = aziende();
    $dipendenti = dipendenti();
    $azienda = $aziende[$spec['azienda']];
    $persone = array_slice($dipendenti[$spec['azienda']], 0, $spec['destinatari']);
    $opzioni = $spec['opzioni'] ?? [];
    $pagine = '';

    foreach ($persone as $persona) {
        $pagine .= match ($spec['tipo']) {
            'cedolino' => cedolino($persona, $azienda, datiCedolino($persona, $spec['periodo'], $spec['emissione'] ?? null), $opzioni),
            'cu' => certificazioneUnica($persona, $azienda, datiCertificazione($persona, $spec['periodo'], $spec['emissione'] ?? null), $opzioni),
            'ferie' => prospettoFerie($persona, $azienda, datiFerie($persona, $spec['periodo'], $spec['emissione'] ?? null), $opzioni),
            'lettera' => lettera($persona, $azienda, array_merge(
                corpiLettera()[$spec['corpo']],
                ['periodo' => $spec['periodo'], 'emissione' => $spec['emissione'] ?? null],
            ), $opzioni),
            default => throw new InvalidArgumentException('Tipo sconosciuto: '.$spec['tipo']),
        };
    }

    return $pagine;
}

// ---------------------------------------------------------------------------
// La specifica del set. Il nome del file dichiara l'esito atteso, cosi' si
// legge dalla cartella senza aprire il PDF.
//
//   NN-tipo-Ndest-esito-motivo.pdf
//
// 'degrado' non e' applicato qui: il PDF esce nitido e viene poi rovinato da
// degrada.py, che lavora sull'immagine (vedi demo/README.md).
// ---------------------------------------------------------------------------

$dataset = [
    ['01-cedolino-5dest-auto-completo', 'cedolino', 'meridiana', 5, 'Marzo 2026', '31/03/2026', []],
    ['02-cedolino-4dest-auto-completo', 'cedolino', 'valconca', 4, 'Marzo 2026', '31/03/2026', []],
    ['03-cedolino-3dest-auto-completo', 'cedolino', 'santelena', 3, 'Aprile 2026', '30/04/2026', []],
    ['04-cedolino-4dest-auto-completo', 'cedolino', 'delta', 4, 'Aprile 2026', '30/04/2026', []],
    ['05-cedolino-4dest-auto-completo', 'cedolino', 'ostuni', 4, 'Maggio 2026', '31/05/2026', []],
    ['06-cu-3dest-auto-completo', 'cu', 'meridiana', 3, '2025', '15/03/2026', []],
    ['07-cu-4dest-auto-completo', 'cu', 'delta', 4, '2025', '15/03/2026', []],
    ['08-ferie-4dest-auto-completo', 'ferie', 'valconca', 4, 'Anno 2026', '30/06/2026', []],
    ['09-premio-4dest-auto-completo', 'lettera', 'ostuni', 4, 'Prot. 2026/0412', '12/05/2026', [], 'premio'],
    ['10-cedolino-5dest-revisione-senza-azienda', 'cedolino', 'meridiana', 5, 'Giugno 2026', '30/06/2026', ['senzaAzienda' => true]],
    ['11-cu-4dest-revisione-senza-cognome', 'cu', 'valconca', 4, '2025', '15/03/2026', ['senzaCognome' => true]],
    ['12-cedolino-3dest-revisione-scansione-pessima', 'cedolino', 'santelena', 3, 'Giugno 2026', '30/06/2026', []],
    ['13-ferie-4dest-revisione-scansione-pessima', 'ferie', 'delta', 4, 'Anno 2026', '30/06/2026', []],
    ['14-cedolino-4dest-quarantena-data-senza-giorno', 'cedolino', 'ostuni', 4, 'Luglio 2026', null, ['dataSoloMese' => true]],
    ['15-tfr-5dest-auto-scansione-leggera', 'lettera', 'meridiana', 5, 'Prot. 2026/0533', '20/06/2026', [], 'tfr'],
    ['16-cedolino-1dest-auto-completo', 'cedolino', 'delta', 1, 'Luglio 2026', '31/07/2026', []],
    ['17-assunzione-1dest-auto-completo', 'lettera', 'ostuni', 1, 'Prot. 2026/0588', '01/07/2026', [], 'assunzione'],
    ['18-cu-1dest-revisione-scansione-media', 'cu', 'santelena', 1, '2025', '15/03/2026', []],
    ['19-cedolino-1dest-quarantena-data-senza-giorno', 'cedolino', 'valconca', 1, 'Agosto 2026', null, ['dataSoloMese' => true]],
    ['20-premio-1dest-revisione-scansione-media', 'lettera', 'meridiana', 1, 'Prot. 2026/0601', '05/07/2026', [], 'premio'],
];

$live = [
    ['live-01-cedolino-4dest-auto-completo', 'cedolino', 'meridiana', 4, 'Settembre 2026', '30/09/2026', []],
    ['live-02-cu-3dest-auto-completo', 'cu', 'delta', 3, '2025', '15/03/2026', []],
    ['live-03-cedolino-3dest-revisione-senza-azienda', 'cedolino', 'valconca', 3, 'Settembre 2026', '30/09/2026', ['senzaAzienda' => true]],
    ['live-04-assunzione-1dest-auto-completo', 'lettera', 'ostuni', 1, 'Prot. 2026/0714', '15/09/2026', [], 'assunzione'],
    ['live-05-cedolino-1dest-revisione-scansione-pessima', 'cedolino', 'santelena', 1, 'Settembre 2026', '30/09/2026', []],
];

$cartelle = ['dataset' => $dataset, 'live' => $live];
$prodotti = 0;

foreach ($cartelle as $cartella => $elenco) {
    foreach ($elenco as $riga) {
        [$nome, $tipo, $azienda, $destinatari, $periodo, $emissione, $opzioni] = $riga;

        $html = componiDocumento([
            'tipo' => $tipo,
            'azienda' => $azienda,
            'destinatari' => $destinatari,
            'periodo' => $periodo,
            'emissione' => $emissione,
            'opzioni' => $opzioni,
            'corpo' => $riga[7] ?? 'premio',
        ]);

        $destinazione = __DIR__."/../pdf/{$cartella}/{$nome}.pdf";
        rendiPdf($html, stileDocumenti(), $destinazione);
        printf("  %-58s %6.1f KB\n", $nome.'.pdf', filesize($destinazione) / 1024);
        $prodotti++;
    }
}

echo "\nGenerati {$prodotti} documenti.\n";
