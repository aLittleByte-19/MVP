# Dati di prova per la demo

Documenti e prompt inventati, costruiti per esercitare la pipeline reale
(Textract + Bedrock) su materiale eterogeneo: tipologie diverse, grafiche
diverse, e soprattutto esiti diversi del riconoscimento.

Nessun dato è reale. Le aziende non esistono, i codici fiscali sono verosimili
ma inventati (con il carattere di controllo calcolato, altrimenti il caso d'uso
li scarterebbe) e le email usano domini sotto `.test`, che l'RFC 2606 riserva
proprio a questo scopo.

## Come sono divisi

```
pdf/dataset/     20 documenti → finiscono nel set di dati e nello snapshot
pdf/live/         5 documenti → mai caricati, si usano davanti al pubblico
prompts/dataset/ 20 prompt    → generati in anticipo, già in cronologia
prompts/live/     5 prompt    → da dare all'Assistant durante la demo
tools/                          generatori e degradatore
```

La separazione fra `dataset` e `live` serve a non mostrare dal vivo dati che il
pubblico ha già visto nel cruscotto.

## Come si legge il nome di un file

```
NN-tipo-Ndest-esito-motivo.pdf
```

Leggendo il nome si sa cosa aspettarsi dal modello: quanti sotto-documenti
dovrebbero nascere dallo split, come dovrebbe finire la revisione, e perché.

## I venti documenti del set

| File | Destinatari | Esito atteso | Perché |
|---|---|---|---|
| 01-cedolino-5dest-auto-completo | 5 | validato | tutti i campi chiave, data per esteso |
| 02-cedolino-4dest-auto-completo | 4 | validato | come sopra |
| 03-cedolino-3dest-auto-completo | 3 | validato | come sopra |
| 04-cedolino-4dest-auto-completo | 4 | validato | come sopra |
| 05-cedolino-4dest-auto-completo | 4 | validato | come sopra |
| 06-cu-3dest-auto-completo | 3 | validato | Certificazione Unica completa |
| 07-cu-4dest-auto-completo | 4 | validato | come sopra |
| 08-ferie-4dest-auto-completo | 4 | validato | prospetto ferie completo |
| 09-premio-4dest-auto-completo | 4 | validato | lettera, testo discorsivo |
| 10-cedolino-5dest-revisione-senza-azienda | 5 | revisione | manca l'intestazione aziendale |
| 11-cu-4dest-revisione-senza-cognome | 4 | revisione | solo il nome di battesimo |
| 12-cedolino-3dest-revisione-scansione-pessima | 3 | revisione | scansione rovinata, OCR ~50 |
| 13-ferie-4dest-revisione-scansione-pessima | 4 | revisione | come sopra |
| 14-cedolino-4dest-quarantena-data-senza-giorno | 4 | quarantena | data col solo mese |
| 15-tfr-5dest-auto-scansione-leggera | 5 | misto | scansione lieve, OCR ~96 |
| 16-cedolino-1dest-auto-completo | 1 | validato | destinatario singolo |
| 17-assunzione-1dest-auto-completo | 1 | validato | lettera a destinatario singolo |
| 18-cu-1dest-revisione-scansione-media | 1 | revisione | scansione intermedia, OCR ~86 |
| 19-cedolino-1dest-quarantena-data-senza-giorno | 1 | quarantena | data col solo mese |
| 20-premio-1dest-revisione-scansione-media | 1 | revisione | scansione intermedia, OCR ~87 |

E i cinque tenuti per la demo dal vivo:

| File | Destinatari | Esito atteso |
|---|---|---|
| live-01-cedolino-4dest-auto-completo | 4 | validato |
| live-02-cu-3dest-auto-completo | 3 | validato |
| live-03-cedolino-3dest-revisione-senza-azienda | 3 | revisione |
| live-04-assunzione-1dest-auto-completo | 1 | validato |
| live-05-cedolino-1dest-revisione-scansione-pessima | 1 | revisione |

## Le tre leve degli esiti

Il punteggio di confidenza è quello del campo chiave più debole, e ogni campo
prende la confidenza della riga OCR da cui proviene (ADR 0013). Da lì le leve:

1. **Campo chiave assente** — niente intestazione aziendale (`senzaAzienda`) o
   solo il nome di battesimo (`senzaCognome`): il punteggio va a zero.
2. **Scansione degradata** — l'unica leva che manda in revisione un documento
   *completo*, perché deve portare l'OCR sotto la soglia di 80.
3. **Data col solo mese** (`dataSoloMese`) — lo schema di validazione accetta
   solo date complete (`^\d{4}-\d{2}-\d{2}$`), quindi il modello restituisce una
   data parziale e il sotto-documento finisce in **quarantena**. Non è
   deterministico: dipende da come il modello formatta quella data volta per
   volta.

## Livelli di degrado, misurati

`tools/degrada.py` rasterizza il PDF e ne rovina l'immagine. La confidenza
dichiarata da Textract non scende in proporzione: fra "lieve" e "media" quasi
non si muove, poi crolla. Valori misurati sulla pipeline reale:

| Livello | OCR misurato | Esito tipico |
|---|---|---|
| lieve | ~96 | misto, punteggi 71-100 |
| media | ~95 | ancora sopra soglia |
| sensibile | ~86 | revisione |
| marcata | ~59 | revisione |
| forte | ~50 | revisione, errori di lettura nei nomi |

**Attenzione al criterio di accettazione**: il Capitolato chiede «confidenza
media OCR ≥ 90%», ed è la media che il cruscotto mostra. Degradare troppi
documenti fa scendere quella media sotto la soglia, e la demo mostrerebbe il
sistema che manca il proprio criterio. Con la composizione attuale la media si
attesta intorno a 92.

## Rigenerare il set

I documenti sono deterministici: gli importi derivano dal codice fiscale della
persona, e il degrado usa un seme fisso. Rigenerare produce gli stessi file.

```bash
docker compose run --rm --no-deps -v "$PWD/demo:/var/www/html/demo" \
  app php demo/tools/genera.php
```

Poi va riapplicato il degrado ai sei documenti che lo prevedono nel nome:

| File | Livello |
|---|---|
| 12-cedolino-3dest-revisione-scansione-pessima | forte |
| 13-ferie-4dest-revisione-scansione-pessima | forte |
| 15-tfr-5dest-auto-scansione-leggera | lieve |
| 18-cu-1dest-revisione-scansione-media | sensibile |
| 20-premio-1dest-revisione-scansione-media | sensibile |
| live-05-cedolino-1dest-revisione-scansione-pessima | forte |

```bash
python demo/tools/degrada.py ingresso.pdf uscita.pdf --intensita forte
```

Prima di caricare conviene verificare che FPDI apra tutto, perché il parser
libero non regge i PDF con cross-reference compressi e fallirebbe a metà del
popolamento:

```bash
docker compose run --rm --no-deps -v "$PWD/demo:/var/www/html/demo" \
  app php demo/tools/prova-fpdi.php
```

## Stato

Documenti e prompt sono pronti. Il popolamento dell'applicativo, le tre
quarantene e i comandi `make apply-demo` / `reset-demo` / `snapshot-demo` sono
ancora da fare.
