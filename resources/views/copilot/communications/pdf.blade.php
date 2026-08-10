<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page {
            margin: 80px 60px 70px 60px;
        }
        body { font-family: Helvetica, Arial, sans-serif; color: #1f2933; }
        .coverWrap { text-align: center; margin-bottom: 24px; }
        .cover { max-width: 100%; max-height: 260px; }
        .eyebrow {
            margin: 0 0 6px;
            color: #8a94a3;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        h1 { font-size: 22px; margin: 0 0 14px; color: #10161f; }
        .rule { border: 0; border-top: 2px solid #d7dce3; margin: 0 0 24px; }
        .body p {
            margin: 0 0 14px;
            font-size: 13px;
            line-height: 1.7;
            text-align: justify;
            white-space: pre-line;
        }
    </style>
</head>
<body>
    @if ($coverDataUri)
        <div class="coverWrap">
            <img class="cover" src="{{ $coverDataUri }}" alt="">
        </div>
    @endif
    <p class="eyebrow">Comunicazione</p>
    <h1>{{ $title }}</h1>
    <hr class="rule">
    <div class="body">
        @foreach (preg_split('/\n{2,}/', trim((string) $body)) as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    </div>
</body>
</html>
