<?php

namespace App\Mvp\Documents\Adapters\Outbound\Pdf;

use App\Mvp\Documents\Domain\Ports\Outbound\SendMessageRendererPort;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageComposition;
use App\Mvp\Support\PdfFooterStamper;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Adapter secondario: implementa {@see SendMessageRendererPort} sopra Dompdf
 * e la vista Blade `copilot.documents.send-message`.
 */
class DompdfSendMessageRenderer implements SendMessageRendererPort
{
    public function __construct(private readonly PdfFooterStamper $footerStamper) {}

    public function renderPdf(SendMessageComposition $composition): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('copilot.documents.send-message', [
            'recipient' => $composition->recipient,
            'subject' => $composition->subject,
            'body' => $composition->body,
            'attachmentFilename' => $composition->attachmentFilename,
        ])->render());
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        $this->footerStamper->stamp($dompdf);

        return $dompdf->output();
    }
}
