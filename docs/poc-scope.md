# Perimetro funzionale della PoC

Questa PoC dimostra solo i flussi principali descritti nell'Analisi dei Requisiti.
La rifinitura di dettaglio, le regole complete e le scelte definitive di UI/UX sono rimandate al PB.

## AI Assistant Generativo

La PoC include:

- generazione di una bozza a partire da prompt, tono e stile;
- validazione minima del prompt mancante o insufficiente;
- anteprima di titolo, testo e immagine di copertina placeholder;
- modifica manuale di titolo e testo;
- rigenerazione della bozza tramite provider fake o Bedrock se configurato;
- eliminazione della bozza corrente;
- salvataggio in bozza;
- invio email simulato tramite Mailpit;
- storico delle generazioni con ricerca e filtro base;
- rating da 1 a 5 e commento opzionale;
- dashboard minima con numero generazioni, prompt salvati, rating medio e feedback recenti.

## AI Co-Pilot documentale

La PoC include:

- upload singolo di documenti PDF;
- controllo minimo di formato e duplicato tramite hash del file;
- inserimento opzionale di classificazione e metadati iniziali in upload;
- avvio asincrono dell'analisi documentale tramite Laravel Queue;
- OCR/parsing placeholder tramite AI worker locale;
- classificazione documento placeholder;
- estrazione metadati principali: dipendente, azienda, nome file, data, pagine, tipologia, descrizione;
- calcolo o simulazione del confidence score;
- split documentale placeholder, con collegamento tra documento originale e porzione destinata al dipendente;
- storico documenti ordinato dal piu recente;
- filtri principali: ricerca libera, stato invio, soglia di confidenza, mese e anno;
- vista dettaglio documento con dati estratti;
- modifica manuale dei campi editabili;
- generazione bozza di invio con destinatario, oggetto e testo;
- invio simulato tramite Mailpit;
- dashboard minima AI Co-Pilot con documenti analizzati, classificazioni corrette simulate, documenti sotto soglia, destinatari riconosciuti e tempo medio di analisi.

## Esclusioni consapevoli dalla PoC

Non sono inclusi nella PoC iniziale:

- gestione avanzata di ruoli, permessi e policy;
- audit trail completo e policy di conservazione;
- retry avanzato, prove di consegna e tracciamento letture;
- entity resolution completa su anagrafiche reali;
- split PDF realmente affidabile per documenti complessi;
- OCR/Textract e Bedrock in modalita production;
- dashboard analitiche avanzate o esportazioni complesse;
- Kubernetes, Terraform, monitoring enterprise e integrazioni NEXUM reali.
