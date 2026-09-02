{{--
    La filigrana, dietro il contenuto.

    Stava sul canvas (`page_text`), che dompdf disegna a rendering concluso:
    finiva sopra il testo e ne copriva le parole. Qui e' un elemento del
    documento con `z-index` negativo, quindi passa sotto, e `position: fixed`
    la ripete su ogni pagina che dompdf impagina. Nel messaggio del Co-Pilot
    resta percio' sulle sole pagine del messaggio: il documento accodato e' il
    foglio originale del destinatario e non si sovrastampa.
--}}
<div class="watermark" aria-hidden="true">{{ $watermark }}</div>
