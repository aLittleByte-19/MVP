<x-filament-panels::page>
    <div class="nexum-admin-page">
        <section class="nexum-toolbar">
            <div>
                <p class="nexum-eyebrow">Configurazione locale</p>
                <p class="nexum-muted">Gestisci simulazioni, credenziali temporanee AWS e pulizia dei dati generati.</p>
            </div>

            <div class="nexum-toolbar-actions">
                <button
                    type="button"
                    class="nexum-button nexum-button-secondary"
                    wire:click="useSimulationDefaults"
                >
                    Preset simulazione
                </button>

                <button
                    type="button"
                    class="nexum-button nexum-button-danger"
                    wire:click="resetData"
                    wire:confirm="Saranno eliminati comunicazioni generate, documenti caricati, split e dati estratti. Continuare?"
                >
                    Reset dati
                </button>

                <button type="submit" form="admin-settings-form" class="nexum-button nexum-button-primary">
                    Salva configurazione
                </button>
            </div>
        </section>

        <dl class="nexum-status-strip">
            <div>
                <dt>Bedrock</dt>
                <dd>{{ $this->runtimeStatus['bedrock'] }}</dd>
            </div>
            <div>
                <dt>Credenziali AWS</dt>
                <dd>{{ $this->runtimeStatus['credentials'] }}</dd>
            </div>
            <div>
                <dt>Analisi</dt>
                <dd>{{ $this->runtimeStatus['analysis'] }}</dd>
            </div>
            <div>
                <dt>OCR</dt>
                <dd>{{ $this->runtimeStatus['ocr'] }}</dd>
            </div>
        </dl>

        <form id="admin-settings-form" wire:submit="save" class="nexum-admin-grid">
            <section class="nexum-panel">
                <div class="nexum-section-heading">
                    <h2>Modalità</h2>
                    <span>PoC</span>
                </div>

                <div class="nexum-form-grid">
                    <label class="nexum-switch-row">
                        <span>
                            <strong>Bedrock reale</strong>
                            <small>Usa le credenziali AWS invece della risposta simulata.</small>
                        </span>
                        <input type="checkbox" wire:model="settings.bedrock_enabled" @checked($this->settings['bedrock_enabled'] ?? false)>
                    </label>

                    <label class="nexum-field">
                        <span>Analisi documenti</span>
                        <select wire:model="settings.document_classifier_driver">
                            <option value="fake" @selected(($this->settings['document_classifier_driver'] ?? 'fake') === 'fake')>Simulata</option>
                            <option value="bedrock" @selected(($this->settings['document_classifier_driver'] ?? 'fake') === 'bedrock')>Bedrock</option>
                        </select>
                    </label>

                    <label class="nexum-field">
                        <span>OCR</span>
                        <select wire:model="settings.document_ocr_driver">
                            <option value="local" @selected(($this->settings['document_ocr_driver'] ?? 'local') === 'local')>Locale / simulato</option>
                            <option value="textract" @selected(($this->settings['document_ocr_driver'] ?? 'local') === 'textract')>AWS Textract</option>
                        </select>
                    </label>

                    <label class="nexum-switch-row">
                        <span>
                            <strong>Textract reale</strong>
                            <small>Abilita l'OCR tramite AWS Textract.</small>
                        </span>
                        <input type="checkbox" wire:model="settings.textract_enabled" @checked($this->settings['textract_enabled'] ?? false)>
                    </label>

                    <label class="nexum-field">
                        <span>Regione Textract</span>
                        <input type="text" wire:model="settings.textract_aws_region" value="{{ $this->settings['textract_aws_region'] ?? '' }}" required>
                    </label>

                    <label class="nexum-field">
                        <span>Soglia confidenza</span>
                        <input type="number" min="0" max="100" wire:model="settings.poc_confidence_threshold" value="{{ $this->settings['poc_confidence_threshold'] ?? 80 }}" required>
                    </label>
                </div>
            </section>

            <section class="nexum-panel">
                <div class="nexum-section-heading">
                    <h2>AWS Bedrock</h2>
                    <span>{{ $this->awsCredentialsStatus }}</span>
                </div>

                <div class="nexum-credential-grid">
                    @foreach ($this->awsCredentialRows as $credential)
                        <div class="nexum-credential-item">
                            <span>{{ $credential['label'] }}</span>
                            <strong>{{ $credential['configured'] ? 'Configurata' : 'Non configurata' }}</strong>
                        </div>
                    @endforeach
                </div>

                <div class="nexum-form-grid">
                    <div class="nexum-field-note">
                        I campi credenziali sono sempre vuoti: inserisci un valore solo se vuoi sostituire quello salvato.
                    </div>

                    <label class="nexum-field">
                        <span>Nuova access key ID</span>
                        <input
                            type="password"
                            wire:model="settings.aws_access_key_id"
                            autocomplete="new-password"
                            placeholder="Lascia vuoto per mantenere"
                        >
                    </label>

                    <label class="nexum-field">
                        <span>Nuova secret access key</span>
                        <input
                            type="password"
                            wire:model="settings.aws_secret_access_key"
                            autocomplete="new-password"
                            placeholder="Lascia vuoto per mantenere"
                        >
                    </label>

                    <label class="nexum-field nexum-field-wide">
                        <span>Nuovo session token</span>
                        <input
                            type="password"
                            wire:model="settings.aws_session_token"
                            autocomplete="new-password"
                            placeholder="Lascia vuoto per mantenere"
                        >
                    </label>

                    <label class="nexum-field">
                        <span>Regione AWS</span>
                        <input type="text" wire:model="settings.aws_default_region" value="{{ $this->settings['aws_default_region'] ?? '' }}" required>
                    </label>

                    <label class="nexum-field">
                        <span>Bedrock model ID</span>
                        <input type="text" wire:model="settings.bedrock_model_id" value="{{ $this->settings['bedrock_model_id'] ?? '' }}" required>
                    </label>

                    <div class="nexum-inline-actions">
                        <button
                            type="button"
                            class="nexum-button nexum-button-danger-soft"
                            wire:click="clearAwsCredentials"
                            wire:confirm="Rimuovere access key, secret e session token dal file .env?"
                        >
                            Rimuovi credenziali
                        </button>
                    </div>
                </div>
            </section>
        </form>
    </div>
</x-filament-panels::page>
