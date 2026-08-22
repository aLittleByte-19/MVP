<?php

namespace App\Mvp\Documents\Adapters\Outbound\Pdf;

use App\Mvp\Documents\Domain\Ports\Outbound\SendMessageRendererPort;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageComposition;
use App\Mvp\Support\PdfFooterStamper;
use App\Mvp\Support\PdfWatermarkStamper;
use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;

/**
 * Adapter secondario: rende il messaggio con Dompdf e gli accoda il documento
 * a cui si riferisce.
 *
 * L'unione passa da Fpdi, la stessa libreria che ha tagliato il documento
 * originale nei sotto-documenti: il file che arriva qui l'ha gia' scritto lei,
 * quindi e' importabile per costruzione. Le pagine del documento si copiano
 * come sono, senza sovrastamparci sopra numeri di pagina o marcatori: e' un
 * documento di terzi, e alterarlo sarebbe peggio che lasciarlo intatto.
 */
class DompdfSendMessageRenderer implements SendMessageRendererPort
{
    /**
     * Il messaggio non lo scrive un modello: e' composto dai dati estratti. La
     * dicitura dice comunque da dove arriva il foglio, nella stessa forma della
     * comunicazione dell'AI Assistant.
     */
    private const ORIGIN = 'Co-Pilot CdL';

    public function __construct(
        private readonly PdfFooterStamper $footerStamper,
        private readonly PdfWatermarkStamper $watermarkStamper,
    ) {}

    public function renderPdf(SendMessageComposition $composition, ?string $attachmentPdf = null): string
    {
        if ($attachmentPdf === null || $attachmentPdf === '') {
            return $this->renderMessage($composition);
        }

        // Le pagine del documento si contano prima di stampare il messaggio:
        // il piede di pagina deve numerare il file finale, non la sola lettera.
        $attachmentPath = $this->temporaryFile($attachmentPdf);

        try {
            $attachmentPages = (new Fpdi)->setSourceFile($attachmentPath);
            $message = $this->renderMessage($composition, $attachmentPages);

            return $this->merge($message, $attachmentPath);
        } finally {
            if (is_file($attachmentPath)) {
                @unlink($attachmentPath);
            }
        }
    }

    private function renderMessage(SendMessageComposition $composition, int $attachmentPages = 0): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('copilot.documents.send-message', [
            'recipient' => $composition->recipient,
            'subject' => $composition->subject,
            'body' => $composition->body,
            'companyName' => $composition->companyName,
            'attachmentFilename' => $composition->attachmentFilename,
        ])->render());
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        // La filigrana sta sulle pagine del messaggio, non su quelle del
        // documento accodato: quello e' il foglio originale del destinatario e
        // non si sovrastampa.
        $this->watermarkStamper->stamp($dompdf, 'Creato da '.self::ORIGIN);

        // Il messaggio e' una pagina sola per costruzione: il totale del file
        // finale e' quella piu' le pagine del documento accodato.
        $this->footerStamper->stamp(
            $dompdf,
            self::ORIGIN,
            $attachmentPages > 0 ? $dompdf->getCanvas()->get_page_count() + $attachmentPages : null,
        );

        return $dompdf->output();
    }

    /**
     * Fpdi legge da file, non da stringa: il messaggio passa da un file
     * temporaneo, rimosso anche quando l'importazione fallisce. Il documento
     * e' gia' su disco, lo ha scritto chi ne ha contato le pagine.
     */
    private function merge(string $message, string $attachmentPath): string
    {
        $messagePath = $this->temporaryFile($message);

        try {
            $pdf = new Fpdi;

            foreach ([$messagePath, $attachmentPath] as $path) {
                $pageCount = $pdf->setSourceFile($path);

                for ($page = 1; $page <= $pageCount; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                }
            }

            return (string) $pdf->Output('S');
        } finally {
            if (is_file($messagePath)) {
                @unlink($messagePath);
            }
        }
    }

    private function temporaryFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mvp-pdf-');

        if ($path === false) {
            throw new \RuntimeException('Impossibile creare il file temporaneo per l\'unione del PDF.');
        }

        file_put_contents($path, $bytes);

        return $path;
    }
}
