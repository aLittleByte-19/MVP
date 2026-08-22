{{--
    Il corpo del documento, diviso in paragrafi sulle righe vuote.

    Le righe che cominciano col segno di elenco diventano un elenco vero, anche
    quando il modello le separa una dall'altra con una riga vuota: sono voci di
    una stessa lista, e rese come paragrafi distinti si allontanavano fra loro
    come se non avessero niente in comune.
--}}
@php
    $blocks = [];

    foreach (preg_split('/\n{2,}/', trim((string) $body)) as $block) {
        $lines = array_map('trim', preg_split('/\n/', trim($block)));
        $isList = $lines !== [] && count(array_filter($lines, static fn ($line) => str_starts_with($line, '•'))) === count($lines);
        $last = array_key_last($blocks);

        if ($isList && $last !== null && $blocks[$last]['list']) {
            $blocks[$last]['lines'] = array_merge($blocks[$last]['lines'], $lines);

            continue;
        }

        $blocks[] = ['list' => $isList, 'lines' => $lines, 'text' => $block];
    }
@endphp

<div class="body">
    @foreach ($blocks as $block)
        @if ($block['list'])
            <ul>
                @foreach ($block['lines'] as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        @else
            <p>{{ $block['text'] }}</p>
        @endif
    @endforeach
</div>
