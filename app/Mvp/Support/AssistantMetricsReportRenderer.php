<?php

namespace App\Mvp\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Report PDF riepilogativo delle metriche dell'AI Assistant (RF34-OB, UC-27):
 * riusa l'array già calcolato da {@see MvpStateService::assistantState()},
 * senza query proprie. Nessuna cache su disco — a differenza del PDF di una
 * singola comunicazione, qui i dati sono un aggregato live che cambia a ogni
 * nuova generazione, quindi si renderizza sempre fresco (stesso principio già
 * in uso per l'export del messaggio di invio in Documents).
 */
class AssistantMetricsReportRenderer
{
    public function __construct(private readonly PdfFooterStamper $footerStamper) {}

    /**
     * @param  array<string, mixed>  $assistantState  L'array ritornato da MvpStateService::assistantState().
     */
    public function render(array $assistantState): string
    {
        // "assistant.drafts" non compare nel pannello dashboard (vive nella
        // Overview, vedi il commento in MvpStateService::assistantState()):
        // il report esporta esattamente cio' che e' visibile in dashboard.
        $metrics = array_values(array_filter(
            $assistantState['metrics'],
            fn (array $metric): bool => $metric['key'] !== 'assistant.drafts',
        ));

        $options = new Options;
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('copilot.assistant.metrics-report', [
            'metrics' => $metrics,
            'recentFeedback' => $assistantState['recentFeedback'],
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->render());
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        // La dicitura del piè di pagina è quella del Capitolato/glossario
        // ("Creato da AI Assistant") e va lasciata verbatim — vedi il
        // docblock di PdfFooterStamper::stamp().
        $this->footerStamper->stamp($dompdf, 'AI Assistant');

        return $dompdf->output();
    }

    public function filename(): string
    {
        return 'report-ai-assistant-'.now()->format('Y-m-d').'.pdf';
    }
}
