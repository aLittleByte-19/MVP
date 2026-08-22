<?php

/**
 * Modelli dei documenti del set di prova.
 *
 * Ogni tipologia ha una sua identita' grafica, perche' il sistema deve
 * riconoscere documenti eterogenei e un set tutto uguale non lo metterebbe
 * alla prova. La palette arriva dall'azienda, cosi' due cedolini di aziende
 * diverse non si somigliano.
 *
 * Le omissioni sono deliberate e servono a pilotare la completezza dei campi
 * chiave da cui dipende il punteggio di confidenza (ADR 0013):
 *   'senzaAzienda' => true   toglie l'intestazione aziendale
 *   'dataSoloMese' => true   scrive il periodo senza giorno: lo schema di
 *                            validazione accetta solo date complete, quindi il
 *                            documento finisce in quarantena
 *   'senzaCognome' => true   lascia il solo nome di battesimo
 */

/** Intestazione comune: marchio, ragione sociale, riferimenti. */
function intestazione(array $azienda, array $opzioni = []): string
{
    if ($opzioni['senzaAzienda'] ?? false) {
        return '<div class="brand"><div class="who"><strong>DOCUMENTO RISERVATO</strong><br>'
            .'copia per il destinatario</div></div>';
    }

    return sprintf(
        '<div class="brand"><div class="mark" style="background: %s">%s</div>'
        .'<div class="who"><strong>%s</strong><br>%s<br>P.IVA %s</div></div>',
        $azienda['ink'],
        htmlspecialchars(mb_substr($azienda['nome'], 0, 1)),
        htmlspecialchars($azienda['nome']),
        htmlspecialchars($azienda['indirizzo']),
        htmlspecialchars($azienda['piva']),
    );
}

/** Blocco data: per esteso se il documento deve validarsi, altrimenti solo mese. */
function bloccoData(string $etichetta, string $periodo, ?string $emissione, array $opzioni = []): string
{
    if ($opzioni['dataSoloMese'] ?? false) {
        return sprintf('<div class="periodo">%s<strong>%s</strong></div>', htmlspecialchars($etichetta), htmlspecialchars($periodo));
    }

    return sprintf(
        '<div class="periodo">%s<strong>%s</strong><div class="emissione">Data di emissione: <strong>%s</strong></div></div>',
        htmlspecialchars($etichetta),
        htmlspecialchars($periodo),
        htmlspecialchars((string) $emissione),
    );
}

/** Nome del destinatario, eventualmente monco per abbassare la completezza. */
function nominativo(array $persona, array $opzioni = []): string
{
    return ($opzioni['senzaCognome'] ?? false)
        ? htmlspecialchars($persona['nome'])
        : htmlspecialchars($persona['nome'].' '.$persona['cognome']);
}

/**
 * Cedolino paga: tabella delle voci retributive e riepilogo dei totali.
 * E' la tipologia piu' densa, con i progressivi annuali a piede pagina.
 */
function cedolino(array $persona, array $azienda, array $dati, array $opzioni = []): string
{
    $righe = '';
    foreach ($dati['voci'] as $voce) {
        $righe .= sprintf(
            '<tr><td>%s</td><td class="n">%s</td><td class="n">%s</td><td class="n">%s</td></tr>',
            htmlspecialchars($voce[0]),
            htmlspecialchars($voce[1]),
            htmlspecialchars($voce[2]),
            htmlspecialchars($voce[3]),
        );
    }

    $testata = intestazione($azienda, $opzioni);
    $data = bloccoData('Periodo di riferimento', $dati['periodo'], $dati['emissione'] ?? null, $opzioni);
    $nome = nominativo($persona, $opzioni);

    return <<<HTML
<div class="foglio">
  <div class="testata" style="border-color: {$azienda['accent']}">{$testata}{$data}</div>
  <h1 style="color: {$azienda['ink']}">Cedolino paga</h1>

  <table class="anagrafica">
    <tr>
      <td class="k">Dipendente</td><td class="v">{$nome}</td>
      <td class="k">Matricola</td><td class="v">{$persona['matricola']}</td>
    </tr>
    <tr>
      <td class="k">Codice fiscale</td><td class="v">{$persona['cf']}</td>
      <td class="k">Livello</td><td class="v">{$persona['livello']}</td>
    </tr>
    <tr>
      <td class="k">Qualifica</td><td class="v">{$persona['qualifica']}</td>
      <td class="k">Email</td><td class="v">{$persona['email']}</td>
    </tr>
  </table>

  <table class="voci">
    <thead style="background: {$azienda['wash']}">
      <tr><th>Descrizione</th><th class="n">Quantita'</th><th class="n">Base</th><th class="n">Importo</th></tr>
    </thead>
    <tbody>{$righe}</tbody>
  </table>

  <table class="totali">
    <tr>
      <td class="k">Totale competenze</td><td class="v n">{$dati['competenze']}</td>
      <td class="k">Totale ritenute</td><td class="v n">{$dati['ritenute']}</td>
      <td class="k netto" style="background: {$azienda['ink']}">Netto in busta</td>
      <td class="v n netto" style="background: {$azienda['ink']}">{$dati['netto']}</td>
    </tr>
  </table>

  <table class="progressivi">
    <thead><tr><th colspan="4">Progressivi dell'anno</th></tr></thead>
    <tr>
      <td class="k">Imponibile previdenziale</td><td class="v n">{$dati['progrPrevidenziale']}</td>
      <td class="k">Imponibile fiscale</td><td class="v n">{$dati['progrFiscale']}</td>
    </tr>
    <tr>
      <td class="k">Ritenute IRPEF</td><td class="v n">{$dati['progrIrpef']}</td>
      <td class="k">Ferie residue</td><td class="v n">{$dati['ferieResidue']} ore</td>
    </tr>
  </table>

  <p class="nota">Documento generato automaticamente dal sistema paghe. Per contestazioni
  rivolgersi all'ufficio del personale entro trenta giorni dalla consegna. Il presente
  prospetto sostituisce a ogni effetto la consegna cartacea.</p>
</div>
HTML;
}

/** Certificazione Unica: prospetto fiscale annuale, impaginato su due colonne. */
function certificazioneUnica(array $persona, array $azienda, array $dati, array $opzioni = []): string
{
    $testata = intestazione($azienda, $opzioni);
    $data = bloccoData('Anno d\'imposta', $dati['periodo'], $dati['emissione'] ?? null, $opzioni);
    $nome = nominativo($persona, $opzioni);

    return <<<HTML
<div class="foglio">
  <div class="testata" style="border-color: {$azienda['accent']}">{$testata}{$data}</div>
  <h1 style="color: {$azienda['ink']}">Certificazione Unica</h1>
  <p class="occhiello">Certificazione dei redditi di lavoro dipendente, equiparati ed assimilati</p>

  <table class="anagrafica">
    <tr>
      <td class="k">Percipiente</td><td class="v">{$nome}</td>
      <td class="k">Codice fiscale</td><td class="v">{$persona['cf']}</td>
    </tr>
    <tr>
      <td class="k">Matricola</td><td class="v">{$persona['matricola']}</td>
      <td class="k">Email</td><td class="v">{$persona['email']}</td>
    </tr>
  </table>

  <div class="duecolonne">
    <table class="quadro">
      <thead style="background: {$azienda['wash']}"><tr><th colspan="2">Dati fiscali</th></tr></thead>
      <tr><td>Totale redditi di lavoro dipendente</td><td class="n">{$dati['redditi']}</td></tr>
      <tr><td>Ritenute IRPEF operate</td><td class="n">{$dati['irpef']}</td></tr>
      <tr><td>Addizionale regionale</td><td class="n">{$dati['regionale']}</td></tr>
      <tr><td>Addizionale comunale</td><td class="n">{$dati['comunale']}</td></tr>
      <tr><td>Giorni di detrazione</td><td class="n">{$dati['giorni']}</td></tr>
    </table>

    <table class="quadro">
      <thead style="background: {$azienda['wash']}"><tr><th colspan="2">Dati previdenziali</th></tr></thead>
      <tr><td>Imponibile previdenziale</td><td class="n">{$dati['imponibile']}</td></tr>
      <tr><td>Contributi a carico del lavoratore</td><td class="n">{$dati['contributi']}</td></tr>
      <tr><td>Gestione di iscrizione</td><td class="n">FPLD</td></tr>
      <tr><td>Settimane utili</td><td class="n">{$dati['settimane']}</td></tr>
      <tr><td>Codice sede INPS</td><td class="n">{$dati['sedeInps']}</td></tr>
    </table>
  </div>

  <p class="nota">Il sostituto d'imposta attesta che i dati riportati corrispondono alle
  scritture contabili. La presente certificazione e' rilasciata ai sensi dell'articolo 4
  del D.P.R. 322/1998.</p>
</div>
HTML;
}

/**
 * Lettera aziendale: assunzione, premio, TFR, chiusura.
 * Impaginata come una lettera vera, senza tabelle: mette alla prova
 * l'estrazione su testo discorsivo invece che su campi incolonnati.
 */
function lettera(array $persona, array $azienda, array $dati, array $opzioni = []): string
{
    $testata = intestazione($azienda, $opzioni);
    $data = bloccoData('Protocollo', $dati['periodo'], $dati['emissione'] ?? null, $opzioni);
    $nome = nominativo($persona, $opzioni);
    $corpo = '';

    foreach ($dati['paragrafi'] as $paragrafo) {
        $corpo .= '<p>'.htmlspecialchars($paragrafo).'</p>';
    }

    return <<<HTML
<div class="foglio">
  <div class="testata" style="border-color: {$azienda['accent']}">{$testata}{$data}</div>

  <div class="destinatario">
    <span class="etichettaLettera">Spett.le</span>
    <strong>{$nome}</strong><br>
    {$persona['qualifica']} &mdash; matricola {$persona['matricola']}<br>
    Codice fiscale {$persona['cf']}<br>
    {$persona['email']}
  </div>

  <h1 style="color: {$azienda['ink']}">{$dati['oggetto']}</h1>

  <div class="corpoLettera">{$corpo}</div>

  <div class="firma">
    <span>Distinti saluti</span>
    <strong>L'ufficio del personale</strong>
    <span>{$azienda['nome']}</span>
  </div>
</div>
HTML;
}

/** Prospetto ferie e permessi: griglia mensile, molto tabellare. */
function prospettoFerie(array $persona, array $azienda, array $dati, array $opzioni = []): string
{
    $testata = intestazione($azienda, $opzioni);
    $data = bloccoData('Periodo di riferimento', $dati['periodo'], $dati['emissione'] ?? null, $opzioni);
    $nome = nominativo($persona, $opzioni);

    $righe = '';
    foreach ($dati['movimenti'] as $movimento) {
        $righe .= sprintf(
            '<tr><td>%s</td><td>%s</td><td class="n">%s</td><td class="n">%s</td></tr>',
            htmlspecialchars($movimento[0]),
            htmlspecialchars($movimento[1]),
            htmlspecialchars($movimento[2]),
            htmlspecialchars($movimento[3]),
        );
    }

    return <<<HTML
<div class="foglio">
  <div class="testata" style="border-color: {$azienda['accent']}">{$testata}{$data}</div>
  <h1 style="color: {$azienda['ink']}">Prospetto ferie e permessi</h1>

  <table class="anagrafica">
    <tr>
      <td class="k">Dipendente</td><td class="v">{$nome}</td>
      <td class="k">Matricola</td><td class="v">{$persona['matricola']}</td>
    </tr>
    <tr>
      <td class="k">Codice fiscale</td><td class="v">{$persona['cf']}</td>
      <td class="k">Email</td><td class="v">{$persona['email']}</td>
    </tr>
  </table>

  <table class="voci">
    <thead style="background: {$azienda['wash']}">
      <tr><th>Data</th><th>Causale</th><th class="n">Ore</th><th class="n">Residuo</th></tr>
    </thead>
    <tbody>{$righe}</tbody>
  </table>

  <table class="totali">
    <tr>
      <td class="k">Ferie maturate</td><td class="v n">{$dati['maturate']} ore</td>
      <td class="k">Ferie godute</td><td class="v n">{$dati['godute']} ore</td>
      <td class="k netto" style="background: {$azienda['ink']}">Residuo</td>
      <td class="v n netto" style="background: {$azienda['ink']}">{$dati['residuo']} ore</td>
    </tr>
  </table>

  <p class="nota">Il residuo e' calcolato alla data di emissione del prospetto. Le richieste
  di fruizione vanno inoltrate con almeno quindici giorni di anticipo.</p>
</div>
HTML;
}

/** Foglio di stile comune, con le varianti per tipologia. */
function stileDocumenti(): string
{
    return <<<'CSS'
@page { margin: 18mm 16mm; }
body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #222; }
.foglio { page-break-after: always; }
.foglio:last-child { page-break-after: auto; }
.testata { display: block; border-bottom: 3px solid; padding-bottom: 8px; margin-bottom: 14px; }
.brand { display: inline-block; }
.mark { display: inline-block; width: 34px; height: 34px; color: #fff;
        text-align: center; line-height: 34px; font-size: 18pt; font-weight: bold; margin-right: 8px; }
.who { display: inline-block; vertical-align: top; font-size: 8pt; line-height: 1.4; }
.periodo { float: right; text-align: right; font-size: 8pt; color: #555; }
.periodo strong { display: block; font-size: 11pt; color: #222; }
.emissione { font-size: 7.5pt; color: #555; margin-top: 3px; font-weight: normal; }
h1 { font-size: 15pt; margin: 0 0 12px; letter-spacing: 0.5px; }
.occhiello { font-size: 8pt; color: #666; margin: -8px 0 12px; font-style: italic; }
table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
.anagrafica td { padding: 5px 6px; border: 1px solid #d8d8d8; }
.anagrafica .k { background: #f6f6f6; font-size: 7.5pt; color: #666; width: 15%; text-transform: uppercase; }
.anagrafica .v { font-weight: bold; width: 35%; }
.voci th { padding: 6px; border: 1px solid #d8d8d8; font-size: 8pt; text-align: left; }
.voci td { padding: 5px 6px; border: 1px solid #e4e4e4; }
.n { text-align: right; }
.totali td { padding: 7px 6px; border: 1px solid #d8d8d8; }
.totali .k { background: #f6f6f6; font-size: 7.5pt; color: #666; text-transform: uppercase; }
.totali .v { font-weight: bold; }
.totali .netto { color: #fff; font-size: 10pt; }
.progressivi th { padding: 5px 6px; border: 1px solid #d8d8d8; background: #fafafa;
                  font-size: 7.5pt; text-transform: uppercase; color: #666; text-align: left; }
.progressivi td { padding: 5px 6px; border: 1px solid #e4e4e4; }
.progressivi .k { font-size: 7.5pt; color: #666; text-transform: uppercase; width: 30%; }
.progressivi .v { font-weight: bold; }
.nota { font-size: 7pt; color: #888; margin-top: 18px; line-height: 1.5; }

/* Certificazione Unica: due quadri affiancati. */
.duecolonne { width: 100%; }
.duecolonne .quadro { width: 48%; display: inline-table; vertical-align: top; margin-right: 2%; }
.quadro th { padding: 6px; border: 1px solid #d8d8d8; font-size: 8pt; text-align: left; }
.quadro td { padding: 5px 6px; border: 1px solid #e4e4e4; font-size: 8pt; }

/* Lettera: niente griglia, testo che respira. */
.destinatario { margin: 4px 0 22px; font-size: 9pt; line-height: 1.5; }
.etichettaLettera { display: block; font-size: 7.5pt; color: #888; text-transform: uppercase; }
.corpoLettera p { margin: 0 0 10px; line-height: 1.65; text-align: justify; }
.firma { margin-top: 28px; font-size: 8.5pt; line-height: 1.6; }
.firma strong { display: block; margin-top: 14px; }
CSS;
}
