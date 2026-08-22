<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $subject }}</title>
    @include('copilot.partials.pdf-styles')
</head>
<body>
    <p class="eyebrow">Comunicazione Copilot CdL</p>
    <h1>{{ $subject }}</h1>
    <div class="rule"></div>

    {{-- Destinatario, azienda e allegato in una tabella. Erano righe con
         un'etichetta `inline-block` di larghezza fissa, che dompdf non colloca
         come un browser: il valore usciva sfalsato rispetto alla propria
         etichetta. --}}
    <table class="meta">
        <tr>
            <td class="k">Destinatario</td>
            <td class="v">{{ $recipient }}</td>
        </tr>
        @if ($companyName)
            <tr>
                <td class="k">Azienda</td>
                <td class="v">{{ $companyName }}</td>
            </tr>
        @endif
        <tr>
            <td class="k">In allegato</td>
            <td class="v">{{ $attachmentFilename }}</td>
        </tr>
    </table>

    @include('copilot.partials.pdf-body', ['body' => $body])

    <p class="note">Il documento segue nelle pagine successive.</p>
</body>
</html>
