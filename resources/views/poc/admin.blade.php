@extends('poc.layout')

@section('title', 'NEXUM | Amministrazione PoC')

@section('nav-label', 'Navigazione amministrazione')

@section('sidebar-nav')
    @include('poc.partials.admin-nav')
@endsection

@section('header-title', 'Amministrazione PoC')

@section('header-actions')
    <div class="button-row admin-toolbar-actions">
        <button type="submit" form="simulation-form" class="secondary-button">Preset simulazione</button>
        <button type="submit" form="reset-form" class="danger-button">Reset dati</button>
        <button type="submit" form="admin-settings-form" class="primary-button">Salva configurazione</button>
    </div>
@endsection

@section('content')
    <div class="view active admin-page">
        @if (session('status'))
          <p class="inline-note">{{ session('status') }}</p>
        @endif

        @if (session('error'))
          <p class="empty-note">{{ session('error') }}</p>
        @endif

        @if ($errors->any())
          <div class="empty-note">
            <strong>Controlla i campi evidenziati.</strong>
            <ul class="admin-error-list">
              @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
              @endforeach
            </ul>
          </div>
        @endif

        <section class="panel">
          <div class="panel-heading">
            <div>
              <p class="eyebrow">Configurazione locale</p>
              <h3>Runtime e servizi AI</h3>
            </div>
          </div>
          <p class="panel-note">Gestisci simulazioni, credenziali temporanee AWS e pulizia dei dati generati.</p>

          <dl class="status-list admin-status-list">
            <div>
              <dt>Bedrock</dt>
              <dd>{{ $runtimeStatus['bedrock'] }}</dd>
            </div>
            <div>
              <dt>Credenziali AWS</dt>
              <dd>{{ $runtimeStatus['credentials'] }}</dd>
            </div>
            <div>
              <dt>Analisi</dt>
              <dd>{{ $runtimeStatus['analysis'] }}</dd>
            </div>
            <div>
              <dt>OCR</dt>
              <dd>{{ $runtimeStatus['ocr'] }}</dd>
            </div>
            <div>
              <dt>Queue</dt>
              <dd>{{ $runtimeStatus['queue'] }}</dd>
            </div>
            <div>
              <dt>Storage documenti</dt>
              <dd>{{ $runtimeStatus['storage'] }}</dd>
            </div>
          </dl>
        </section>

        <form id="admin-settings-form" action="{{ route('admin.save') }}" method="post" class="admin-grid">
          @csrf

          <section class="panel">
            <div class="panel-heading">
              <div>
                <p class="eyebrow">Modalità</p>
                <h3>Driver PoC</h3>
              </div>
            </div>

            <div class="form-grid">
              <label class="switch-row">
                <span>
                  <strong>Bedrock reale</strong>
                  <small>Usa le credenziali AWS invece della risposta simulata.</small>
                </span>
                <input type="hidden" name="bedrock_enabled" value="0">
                <input type="checkbox" name="bedrock_enabled" value="1" @checked((bool) old('bedrock_enabled', $settings['bedrock_enabled']))>
              </label>

              <label class="field">
                <span>Analisi documenti</span>
                <select name="document_classifier_driver" required>
                  <option value="fake" @selected(old('document_classifier_driver', $settings['document_classifier_driver']) === 'fake')>Simulata</option>
                  <option value="bedrock" @selected(old('document_classifier_driver', $settings['document_classifier_driver']) === 'bedrock')>Bedrock</option>
                </select>
              </label>

              <label class="field">
                <span>OCR</span>
                <select name="document_ocr_driver" required>
                  <option value="local" @selected(old('document_ocr_driver', $settings['document_ocr_driver']) === 'local')>Locale / simulato</option>
                  <option value="textract" @selected(old('document_ocr_driver', $settings['document_ocr_driver']) === 'textract')>AWS Textract</option>
                </select>
              </label>

              <label class="switch-row">
                <span>
                  <strong>Textract reale</strong>
                  <small>Abilita l'OCR tramite AWS Textract.</small>
                </span>
                <input type="hidden" name="textract_enabled" value="0">
                <input type="checkbox" name="textract_enabled" value="1" @checked((bool) old('textract_enabled', $settings['textract_enabled']))>
              </label>

              <label class="field">
                <span>Regione Textract</span>
                <input type="text" name="textract_aws_region" value="{{ old('textract_aws_region', $settings['textract_aws_region']) }}" required>
              </label>

              <label class="field">
                <span>Soglia confidenza</span>
                <input type="number" name="poc_confidence_threshold" min="0" max="100" value="{{ old('poc_confidence_threshold', $settings['poc_confidence_threshold']) }}" required>
              </label>
            </div>
          </section>

          <section class="panel">
            <div class="panel-heading">
              <div>
                <p class="eyebrow">AWS Bedrock</p>
                <h3>Credenziali e modello</h3>
              </div>
              <span class="admin-pill">{{ $awsCredentialsStatus }}</span>
            </div>

            <div class="credential-grid">
              @foreach ($awsCredentialRows as $credential)
                <div class="credential-item">
                  <span>{{ $credential['label'] }}</span>
                  <strong>{{ $credential['configured'] ? 'Configurata' : 'Non configurata' }}</strong>
                </div>
              @endforeach
            </div>

            <div class="form-grid">
              <p class="field-note">I campi credenziali sono sempre vuoti: inserisci un valore solo se vuoi sostituire quello salvato.</p>
              <p class="field-note">Al salvataggio la web app applica subito la nuova configurazione e riavvia la queue Redis per i job successivi.</p>

              <label class="field">
                <span>Nuova access key ID</span>
                <input type="password" name="aws_access_key_id" autocomplete="new-password" placeholder="Lascia vuoto per mantenere">
              </label>

              <label class="field">
                <span>Nuova secret access key</span>
                <input type="password" name="aws_secret_access_key" autocomplete="new-password" placeholder="Lascia vuoto per mantenere">
              </label>

              <label class="field full-field">
                <span>Nuovo session token</span>
                <input type="password" name="aws_session_token" autocomplete="new-password" placeholder="Lascia vuoto per mantenere">
              </label>

              <label class="field">
                <span>Regione AWS</span>
                <input type="text" name="aws_default_region" value="{{ old('aws_default_region', $settings['aws_default_region']) }}" required>
              </label>

              <label class="field">
                <span>Bedrock model ID</span>
                <input type="text" name="bedrock_model_id" value="{{ old('bedrock_model_id', $settings['bedrock_model_id']) }}" required>
              </label>
            </div>
          </section>
        </form>

        <form id="simulation-form" action="{{ route('admin.simulation') }}" method="post">
          @csrf
        </form>

        <form id="reset-form" action="{{ route('admin.reset-data') }}" method="post" onsubmit="return confirm('Saranno eliminati comunicazioni generate, documenti caricati, split e dati estratti. Continuare?')">
          @csrf
        </form>

        <form action="{{ route('admin.clear-credentials') }}" method="post" class="panel">
          @csrf
          <div class="panel-heading">
            <div>
              <p class="eyebrow">Credenziali</p>
              <h3>Rimozione rapida</h3>
            </div>
          </div>
          <p class="panel-note">Disabilita Bedrock reale e rimuove access key, secret e session token dal file `.env`.</p>
          <div class="button-row">
            <button type="submit" class="danger-soft-button" onclick="return confirm('Rimuovere access key, secret e session token dal file .env?')">Rimuovi credenziali AWS</button>
          </div>
        </form>
    </div>
@endsection
