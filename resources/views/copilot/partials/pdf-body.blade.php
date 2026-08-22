{{--
    Il corpo del documento, diviso in paragrafi sulle righe vuote.

    Un blocco le cui righe cominciano tutte col segno di elenco diventa un
    elenco vero: il testo che arriva qui e' testo semplice, senza Markdown, e
    il punto elenco e' l'unica struttura che puo' portare.
--}}
<div class="body">
    @foreach (preg_split('/\n{2,}/', trim((string) $body)) as $block)
        @php
            $lines = preg_split('/\n/', trim($block));
            $bulleted = array_filter($lines, static fn ($line) => str_starts_with(trim($line), '•'));
        @endphp

        @if (count($bulleted) === count($lines))
            <ul>
                @foreach ($lines as $line)
                    <li>{{ trim($line) }}</li>
                @endforeach
            </ul>
        @else
            <p>{{ $block }}</p>
        @endif
    @endforeach
</div>
