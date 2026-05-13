<section class="view" data-view="assistant">
  <article class="panel flow-step" id="assistant-compose">
    <div class="panel-heading">
      <div>
        <p class="eyebrow">AI Assistant</p>
        <h3>Genera una bozza</h3>
      </div>
    </div>

    <p class="panel-note">
      Inserisci il contenuto da generare e scegli solo tono e stile. La PoC produce titolo e testo revisionabili.
    </p>

    <div class="field-stack">
      <label class="field">
        <span>Prompt</span>
        <textarea id="prompt-input" rows="6" placeholder="Descrivi il contenuto da generare"></textarea>
      </label>

      <div class="form-grid">
        <label class="field">
          <span>Tono</span>
          <select id="tone-select">
            <option>Chiaro e diretto</option>
            <option>Più istituzionale</option>
            <option>Più sintetico</option>
            <option>Empatico</option>
            <option>Tecnico</option>
          </select>
        </label>

        <label class="field">
          <span>Stile</span>
          <select id="style-select">
            <option>Testo informativo</option>
            <option>Avviso operativo</option>
            <option>Aggiornamento breve</option>
          </select>
        </label>
      </div>
    </div>

    <div class="button-row">
      <button class="primary-button" id="generate-button" type="button">Genera bozza</button>
    </div>

    <p class="status-message" id="assistant-compose-note">La bozza comparirà qui sotto.</p>
  </article>

  <article class="panel flow-step locked" id="assistant-result" aria-disabled="true">
    <div class="panel-heading">
      <div>
        <p class="eyebrow">Risultato</p>
        <h3>Bozza generata</h3>
      </div>
    </div>

    <div class="editor-stack">
      <label class="field">
        <span>Titolo</span>
        <input id="generated-title-input" type="text" value="" placeholder="Non disponibile">
      </label>

      <label class="field">
        <span>Testo</span>
        <textarea id="generated-body-input" rows="10" placeholder="Non disponibile"></textarea>
      </label>

      <div class="meta-row">
        <span id="meta-chars">0 caratteri</span>
        <span id="meta-time">0 min lettura</span>
        <span id="assistant-status">In attesa</span>
      </div>
    </div>
  </article>
</section>
