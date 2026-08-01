<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $subject }}</title>
    <style>
        @page {
            margin: 80px 60px 70px 60px;
        }
        body { font-family: Helvetica, Arial, sans-serif; color: #1f2933; }
        .eyebrow {
            margin: 0 0 6px;
            color: #8a94a3;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .field {
            margin: 0 0 6px;
            font-size: 12px;
        }
        .field .label {
            display: inline-block;
            width: 80px;
            color: #566f85;
            font-weight: bold;
        }
        .field .value { color: #18324a; }
        h1 { font-size: 20px; margin: 18px 0 14px; color: #10161f; }
        .rule { border: 0; border-top: 2px solid #d7dce3; margin: 0 0 24px; }
        .body p {
            margin: 0 0 14px;
            font-size: 13px;
            line-height: 1.7;
            text-align: justify;
            white-space: pre-line;
        }
        .attachment {
            margin-top: 24px;
            padding-top: 12px;
            border-top: 1px solid #e4edf5;
            color: #8a94a3;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <p class="eyebrow">Messaggio di invio</p>
    <p class="field"><span class="label">A:</span><span class="value">{{ $recipient }}</span></p>
    <h1>{{ $subject }}</h1>
    <hr class="rule">
    <div class="body">
        @foreach (preg_split('/\n{2,}/', trim((string) $body)) as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach
    </div>
    <p class="attachment">Documento allegato: {{ $attachmentFilename }}</p>
</body>
</html>
