<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Report AI Assistant</title>
    @include('copilot.partials.pdf-styles')
</head>
<body>
    @include('copilot.partials.pdf-watermark', ['watermark' => 'Report AI Assistant'])

    <p class="eyebrow">AI Assistant — Report metriche</p>
    <h1>Report riepilogativo</h1>
    <div class="rule"></div>

    <table class="meta">
        @foreach ($metrics as $metric)
            <tr>
                <td class="k">{{ $metric['label'] }}</td>
                <td class="v">
                    {{ $metric['value'] }}{{ isset($metric['unit']) ? ' '.$metric['unit'] : '' }}
                    @if (isset($metric['outOf']))
                        su {{ $metric['outOf'] }}
                    @endif
                    @if (isset($metric['sampleSize']))
                        (su {{ $metric['sampleSize'] }} valutazioni)
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    <p class="eyebrow">Feedback recenti</p>
    @if (count($recentFeedback) === 0)
        <p class="note">Nessun feedback disponibile.</p>
    @else
        <table class="meta">
            @foreach ($recentFeedback as $feedback)
                <tr>
                    <td class="k">{{ $feedback['ratedAt'] ?? '—' }}</td>
                    <td class="v">
                        {{ $feedback['rating'] }} / 5
                        @if (! empty($feedback['ratingComment']))
                            — {{ $feedback['ratingComment'] }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <p class="note">Generato il {{ $generatedAt }}.</p>
</body>
</html>
