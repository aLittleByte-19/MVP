<?php

namespace App\Copilot\Documents\Services;

use App\Copilot\Support\PdfFooterStamper;
use App\Models\Copilot\SubDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;

/**
 * Compone destinatario/oggetto/testo del messaggio di invio precompilato
 * (UC-48/48.1/48.2/48.3) dai dati già estratti dal documento: nessuna
 * generazione AI, calcolato al volo a ogni richiesta a meno che l'operatore
 * non abbia corretto uno dei campi (UC-50/51/52), nel qual caso vince
 * l'override persistito su `sub_documents`.
 */
class SubDocumentSendMessageService
{
    public function __construct(
        private readonly PdfFooterStamper $footerStamper,
    ) {}

    /**
     * @return array{recipient: string, subject: string, body: string}
     */
    public function compose(SubDocument $subDocument): array
    {
        $data = $subDocument->extractedData;
        $employeeName = trim(($data?->employee_first_name ?? '').' '.($data?->employee_last_name ?? ''));
        $documentType = $data?->document_type;
        $companyName = $data?->company_name;
        $documentDate = $data?->document_date?->format('d/m/Y');

        return [
            'recipient' => $subDocument->send_recipient_override
                ?: ($employeeName !== '' ? $employeeName : 'Destinatario non disponibile'),
            'subject' => $subDocument->send_subject_override
                ?: ($documentType ? "Invio documento — {$documentType}" : 'Invio documento'),
            'body' => $subDocument->send_body_override
                ?: $this->composeBody($employeeName, $documentType, $companyName, $documentDate, $data?->description),
        ];
    }

    public function renderPdf(SubDocument $subDocument): string
    {
        $composed = $this->compose($subDocument);

        $options = new Options;
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('copilot.documents.send-message', [
            'recipient' => $composed['recipient'],
            'subject' => $composed['subject'],
            'body' => $composed['body'],
            'attachmentFilename' => $subDocument->originalDocument?->original_filename ?: 'documento.pdf',
        ])->render());
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        $this->footerStamper->stamp($dompdf);

        return $dompdf->output();
    }

    public function filename(SubDocument $subDocument): string
    {
        $slug = Str::slug($this->compose($subDocument)['subject']);

        return ($slug !== '' ? $slug : 'messaggio-invio-'.$subDocument->id).'.pdf';
    }

    private function composeBody(string $employeeName, ?string $documentType, ?string $companyName, ?string $documentDate, ?string $description): string
    {
        $greeting = $employeeName !== '' ? "Gentile {$employeeName}," : 'Gentile destinatario,';

        $documentLabel = $documentType ?: 'documento';
        $reference = "in allegato trova il documento \"{$documentLabel}\"";

        if ($companyName) {
            $reference .= " relativo a {$companyName}";
        }

        if ($documentDate) {
            $reference .= " del {$documentDate}";
        }

        $reference .= '.';

        $lines = [$greeting, '', $reference];

        if ($description) {
            $lines[] = '';
            $lines[] = $description;
        }

        $lines[] = '';
        $lines[] = 'Cordiali saluti.';

        return implode("\n", $lines);
    }
}
