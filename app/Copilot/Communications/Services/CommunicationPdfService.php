<?php

namespace App\Copilot\Communications\Services;

use App\Copilot\Communications\Enums\CoverImageStatus;
use App\Copilot\Support\PdfFooterStamper;
use App\Models\Copilot\Communication;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;

/**
 * Renders a communication's title/body/cover into the finalized PDF used by
 * both the preview (UC-10) and export (UC-11) endpoints, stamped with the
 * "Creato da AI Assistant" transparency marker both use cases require.
 */
class CommunicationPdfService
{
    private const WATERMARK_TEXT = 'Creato da AI Assistant';

    public function __construct(
        private readonly PdfFooterStamper $footerStamper,
    ) {}

    public function render(Communication $communication): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('copilot.communications.pdf', [
            'communication' => $communication,
            'coverDataUri' => $this->coverDataUri($communication),
        ])->render());
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        $this->stampWatermark($dompdf);
        $this->footerStamper->stamp($dompdf);

        return $dompdf->output();
    }

    public function filename(Communication $communication): string
    {
        $slug = Str::slug((string) $communication->generated_title);

        return ($slug !== '' ? $slug : 'comunicazione-'.$communication->id).'.pdf';
    }

    /**
     * Il testo viene ripetuto automaticamente su ogni pagina dall'API canvas di
     * dompdf: un grigio chiaro simula la trasparenza in modo affidabile, a
     * differenza di setOpacity() sul testo canvas (incoerente tra le versioni).
     */
    private function stampWatermark(Dompdf $dompdf): void
    {
        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $font = $fontMetrics->getFont('helvetica', 'bold');
        $size = 15;
        $color = [0.88, 0.88, 0.88];

        $textWidth = $fontMetrics->getTextWidth(self::WATERMARK_TEXT, $font, $size);
        $x = ($canvas->get_width() - $textWidth) / 2;
        $y = $canvas->get_height() / 2;

        $canvas->page_text($x, $y, self::WATERMARK_TEXT, $font, $size, $color, 0.0, 0.0, 45.0);
    }

    private function coverDataUri(Communication $communication): ?string
    {
        if ($communication->cover_status !== CoverImageStatus::Ready || ! $communication->cover_image_path) {
            return null;
        }

        $disk = Storage::disk((string) config('mvp.communications.cover_disk', config('filesystems.default', 'local')));

        try {
            if (! $disk->exists($communication->cover_image_path)) {
                return null;
            }

            $bytes = $disk->get($communication->cover_image_path);
        } catch (FilesystemException) {
            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        return 'data:'.($communication->cover_image_mime ?: 'image/png').';base64,'.base64_encode($bytes);
    }
}
