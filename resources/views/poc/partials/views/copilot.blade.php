<section class="view" data-view="copilot">
  <article class="panel flow-step" id="copilot-upload">
    <div class="panel-heading">
      <div>
        <p class="eyebrow">Co-Pilot CdL</p>
        <h2>Rileva campi e split iniziale</h2>
      </div>
    </div>

    <p class="panel-note">
      Carica un PDF. La PoC salva il documento, prova lo split per destinatario e mostra i campi rilevati dall'OCR/AI.
    </p>

    <button class="upload-box" id="upload-box" type="button">
      <strong>Seleziona un PDF</strong>
      <span>Il sistema avvia automaticamente split iniziale ed estrazione campi.</span>
    </button>
    <input class="hidden" id="document-file-input" name="document" type="file" accept="application/pdf" aria-label="Seleziona un PDF">

    <ul class="compact-list">
      <li><strong>Stato</strong><span id="upload-state">In attesa di caricamento</span></li>
      <li><strong>Output</strong><span id="upload-output">I risultati compariranno nella sezione sottostante.</span></li>
    </ul>
  </article>

  <article class="panel" id="copilot-results">
    <div class="panel-heading">
      <div>
        <p class="eyebrow">Risultati</p>
        <h2>Documenti rilevati</h2>
      </div>
    </div>

    <ul class="status-list compact-kpi-list" id="document-summary-list">
      <li><strong>0</strong><span>Sotto-documenti</span></li>
      <li><strong>0</strong><span>Campi con confidenza</span></li>
      <li><strong>0</strong><span>Da verificare</span></li>
    </ul>

    <ul class="history-list document-history" id="document-history"></ul>
    <p class="empty-note" id="document-empty">Nessun documento analizzato.</p>
  </article>

  <article class="panel is-hidden" id="copilot-detail">
    <div class="panel-heading">
      <div>
        <p class="eyebrow">Dettaglio</p>
        <h2 id="detail-title">Sotto-documento selezionato</h2>
      </div>
    </div>

    <div class="document-detail-grid">
      <div class="document-preview">
        <p class="eyebrow">Split iniziale</p>
        <strong id="detail-preview-title">Documento</strong>
        <span id="detail-preview-meta">Nome file e pagine</span>
        <div class="document-preview-lines" id="detail-preview-lines"></div>
        <div class="document-preview-frame" id="detail-preview-frame">
          <span>Preview documento non disponibile.</span>
        </div>
      </div>

      <div class="extracted-card document-inspector">
        <div class="inspector-heading">
          <p class="section-label">Campi rilevati</p>
        </div>

        <div class="form-grid">
          <label class="field">
            <span>Nome e cognome</span>
            <input id="detail-employee-input" type="text" readonly>
          </label>

          <label class="field">
            <span>Azienda</span>
            <input id="detail-company-input" type="text" readonly>
          </label>

          <label class="field">
            <span>Nome file</span>
            <input id="detail-file-input" type="text" readonly>
          </label>

          <label class="field">
            <span>Data documento</span>
            <input id="detail-date-input" type="text" readonly>
          </label>

          <label class="field">
            <span>Numero pagine</span>
            <input id="detail-pages-input" type="text" readonly>
          </label>

          <label class="field">
            <span>Tipologia documento</span>
            <input id="detail-type-input" type="text" readonly>
          </label>

          <label class="field">
            <span>Confidenza</span>
            <input id="detail-confidence-input" type="text" readonly>
          </label>

          <label class="field full-field">
            <span>Breve descrizione</span>
            <textarea id="detail-description-input" rows="3" readonly></textarea>
          </label>
        </div>
      </div>
    </div>
  </article>
</section>
