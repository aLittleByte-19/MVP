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

        <form id="admin-settings-form" wire:submit="save" class="nexum-admin-grid">
            <section class="nexum-panel">
                <div class="nexum-section-heading">
                    <h2>AWS Bedrock</h2>
                    <span>{{ filter_var(env('BEDROCK_ENABLED', false), FILTER_VALIDATE_BOOL) ? 'Reale' : 'Simulato' }}</span>
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
                        <span>AWS access key ID</span>
                        <input type="text" wire:model="settings.aws_access_key_id" value="{{ $this->settings['aws_access_key_id'] ?? '' }}" autocomplete="off">
                    </label>

                    <label class="nexum-field">
                        <span>AWS secret access key</span>
                        <input
                            type="password"
                            wire:model="settings.aws_secret_access_key"
                            autocomplete="new-password"
                            placeholder="{{ filled(env('AWS_SECRET_ACCESS_KEY')) ? 'Gia configurata: lascia vuoto per conservarla' : '' }}"
                        >
                    </label>

                    <label class="nexum-field nexum-field-wide">
                        <span>AWS session token</span>
                        <input
                            type="password"
                            wire:model="settings.aws_session_token"
                            autocomplete="new-password"
                            placeholder="{{ filled(env('AWS_SESSION_TOKEN')) ? 'Gia configurato: lascia vuoto per conservarlo' : '' }}"
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
                </div>
            </section>

            <section class="nexum-panel">
                <div class="nexum-section-heading">
                    <h2>Elaborazioni</h2>
                    <span>{{ env('DOCUMENT_CLASSIFIER_DRIVER', 'fake') === 'bedrock' ? 'Bedrock' : 'Simulata' }}</span>
                </div>

                <div class="nexum-form-grid">
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

            <section class="nexum-panel nexum-admin-status">
                <div class="nexum-section-heading">
                    <h2>Runtime</h2>
                </div>

                <dl class="nexum-meta-grid">
                    <div>
                        <dt>Bedrock</dt>
                        <dd>{{ filter_var(env('BEDROCK_ENABLED', false), FILTER_VALIDATE_BOOL) ? 'Reale' : 'Simulato' }}</dd>
                    </div>
                    <div>
                        <dt>Analisi</dt>
                        <dd>{{ env('DOCUMENT_CLASSIFIER_DRIVER', 'fake') === 'bedrock' ? 'Bedrock' : 'Simulata' }}</dd>
                    </div>
                    <div>
                        <dt>OCR</dt>
                        <dd>{{ env('DOCUMENT_OCR_DRIVER', 'local') === 'textract' ? 'Textract' : 'Locale' }}</dd>
                    </div>
                </dl>

            </section>
        </form>
    </div>
</x-filament-panels::page>
