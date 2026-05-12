<x-filament-panels::page>
    <div class="nexum-flow">
        <form wire:submit="process" class="nexum-panel">
            {{ $this->form }}

            <div class="mt-6 flex justify-end">
                <x-filament::button type="submit" icon="heroicon-m-document-magnifying-glass">
                    Analizza documento
                </x-filament::button>
            </div>
        </form>

        @if ($this->processedDocument)
            <section class="nexum-panel nexum-result">
                <div>
                    <p class="nexum-eyebrow">Ultimo documento</p>
                    <h2>{{ $this->processedDocument->original_filename }}</h2>
                </div>

                <dl class="nexum-meta-grid">
                    <div>
                        <dt>Stato</dt>
                        <dd>{{ $this->processedDocument->processing_status->label() }}</dd>
                    </div>
                    <div>
                        <dt>Sotto-documenti</dt>
                        <dd>{{ $this->processedDocument->subDocuments->count() }}</dd>
                    </div>
                    <div>
                        <dt>Creato</dt>
                        <dd>{{ $this->processedDocument->created_at?->format('d/m/Y H:i') }}</dd>
                    </div>
                </dl>

                <div class="nexum-list">
                    @forelse ($this->processedDocument->subDocuments as $subDocument)
                        <div class="nexum-list-item">
                            <div>
                                <strong>
                                    {{ trim(($subDocument->extractedData?->employee_first_name ?? '') . ' ' . ($subDocument->extractedData?->employee_last_name ?? '')) ?: 'Documento rilevato' }}
                                </strong>
                                <span>Pagine {{ $subDocument->start_page }}-{{ $subDocument->end_page }}</span>
                            </div>
                            <a href="{{ route('poc.documents.preview', ['subDocument' => $subDocument]) }}" target="_blank" rel="noreferrer">
                                Anteprima
                            </a>
                        </div>
                    @empty
                        <p class="nexum-muted">Nessuno split disponibile.</p>
                    @endforelse
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
