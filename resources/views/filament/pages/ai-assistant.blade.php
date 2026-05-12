<x-filament-panels::page>
    <div class="nexum-flow">
        <form wire:submit="generate" class="nexum-panel">
            {{ $this->form }}

            <div class="mt-6 flex justify-end">
                <x-filament::button type="submit" icon="heroicon-m-sparkles">
                    Genera bozza
                </x-filament::button>
            </div>
        </form>

        @if ($this->generatedCommunication)
            <section class="nexum-panel nexum-result">
                <div>
                    <p class="nexum-eyebrow">Risultato</p>
                    <h2>{{ $this->generatedCommunication->generated_title ?: 'Bozza senza titolo' }}</h2>
                </div>

                <div class="nexum-copy">
                    {!! nl2br(e($this->generatedCommunication->generated_body ?: 'Testo non disponibile.')) !!}
                </div>

                <dl class="nexum-meta-grid">
                    <div>
                        <dt>Tono</dt>
                        <dd>{{ $this->generatedCommunication->tone }}</dd>
                    </div>
                    <div>
                        <dt>Stile</dt>
                        <dd>{{ $this->generatedCommunication->style }}</dd>
                    </div>
                    <div>
                        <dt>Stato</dt>
                        <dd>{{ $this->generatedCommunication->status->label() }}</dd>
                    </div>
                </dl>
            </section>
        @endif
    </div>
</x-filament-panels::page>
