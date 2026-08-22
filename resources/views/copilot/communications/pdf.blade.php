<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('copilot.partials.pdf-styles')
</head>
<body>
    @include('copilot.partials.pdf-watermark', ['watermark' => 'Creato da AI Assistant'])

    <p class="eyebrow">Comunicazione AI Assistant</p>
    <h1>{{ $title }}</h1>
    <div class="rule"></div>

    @if ($coverDataUri)
        <div class="coverWrap">
            <img class="cover" src="{{ $coverDataUri }}" alt="">
        </div>
    @endif

    @include('copilot.partials.pdf-body', ['body' => $body])
</body>
</html>
