{{--
    Foglio di stile condiviso dai PDF generati dal sistema: la comunicazione
    dell'AI Assistant e il messaggio di accompagnamento del Co-Pilot. I colori
    sono quelli del design system della SPA (tokens.css) scritti per esteso,
    perche' dompdf non conosce le custom property CSS.

    Il testo e' allineato a sinistra e non giustificato: dompdf non sillaba, e
    su una colonna stretta il giustificato apriva fra le parole i corridoi
    bianchi che si vedevano nella versione precedente.
--}}
<style>
    @page { margin: 74px 62px 76px 62px; }

    body {
        font-family: Helvetica, Arial, sans-serif;
        color: #18324a;
        font-size: 12.5px;
        line-height: 1.62;
    }

    .eyebrow {
        margin: 0 0 5px;
        color: #566f85;
        font-size: 9px;
        font-weight: bold;
        letter-spacing: 1.4px;
        text-transform: uppercase;
    }

    h1 {
        margin: 0 0 12px;
        color: #10161f;
        font-size: 21px;
        line-height: 1.25;
    }

    /* Filo di apertura sotto il titolo: corto e nel colore del marchio, fa da
       firma. Una riga grigia a tutta larghezza divideva soltanto. */
    .rule {
        width: 64px;
        height: 3px;
        margin: 0 0 24px;
        background: #245170;
        font-size: 0;
        line-height: 0;
    }

    .meta {
        width: 100%;
        margin: 0 0 26px;
        border-collapse: collapse;
        background: #f5f9fc;
        border: 1px solid #e4edf5;
    }

    .meta td {
        padding: 8px 12px;
        vertical-align: top;
        font-size: 11.5px;
    }

    .meta td.k {
        width: 112px;
        color: #566f85;
        font-weight: bold;
        letter-spacing: 0.4px;
        text-transform: uppercase;
        font-size: 9.5px;
    }

    .meta td.v { color: #18324a; }

    .body p {
        margin: 0 0 13px;
        white-space: pre-line;
    }

    /* Elenchi: il segno resta appeso a sinistra e le righe successive
       rientrano sotto il testo, non sotto il punto. */
    .body ul { margin: 0 0 13px; padding: 0; list-style: none; }
    .body li { margin: 0 0 6px; padding-left: 15px; text-indent: -15px; }

    .note {
        margin: 28px 0 0;
        padding-top: 11px;
        border-top: 1px solid #e4edf5;
        color: #566f85;
        font-size: 10.5px;
    }

    /* Filigrana: grande, molto chiara, lettere spaziate, in diagonale come
       sui documenti ufficiali. Il colore e' quello delle superfici tenui del
       design system: si legge quando la si cerca e sparisce sotto il testo. */
    .watermark {
        position: fixed;
        top: 42%;
        left: -10%;
        width: 120%;
        z-index: -1;
        transform: rotate(-45deg);
        color: #e4edf5;
        font-size: 44px;
        font-weight: bold;
        letter-spacing: 5px;
        text-align: center;
        text-transform: uppercase;
    }

    .coverWrap { margin: 0 0 24px; text-align: center; }
    .cover { max-width: 100%; max-height: 250px; }
</style>
