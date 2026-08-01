<?php

namespace App\Mvp\Communications\Services;

use App\Models\Communication;
use App\Mvp\Communications\Enums\CoverImageStatus;
use App\Mvp\Support\PdfFooterStamper;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Throwable;

/**
 * Renders a communication's title/body/cover into the finalized PDF used by
 * both the preview (UC-10) and export (UC-11) endpoints, stamped with the
 * "Creato da AI Assistant" transparency marker both use cases require.
 */
class CommunicationPdfService
{
    private const WATERMARK_TEXT = 'Creato da AI Assistant';

    /**
     * Va incrementata a ogni modifica del template Blade, del watermark o del
     * pie' di pagina: entra nel fingerprint e invalida i PDF gia' materializzati
     * che altrimenti resterebbero serviti con il layout vecchio.
     */
    private const RENDER_VERSION = 1;

    public function __construct(
        private readonly PdfFooterStamper $footerStamper,
    ) {}

    /**
     * Impronta del contenuto renderizzato: stessa impronta => stesso PDF byte
     * per byte. Usata sia come chiave della copia materializzata sia come ETag,
     * cosi' un client che ricarica l'anteprima non fa ripartire dompdf.
     */
    public function fingerprint(Communication $communication): string
    {
        return sha1(implode('|', [
            self::RENDER_VERSION,
            $communication->id,
            (string) $communication->generated_title,
            (string) $communication->generated_body,
            $communication->cover_status->value,
            (string) $communication->cover_image_path,
            (string) $communication->cover_image_mime,
        ]));
    }

    public function render(Communication $communication): string
    {
        $fingerprint = $this->fingerprint($communication);
        $cachePath = $this->cachePath($communication, $fingerprint);
        $disk = $this->cacheDisk();

        if ($disk !== null) {
            try {
                if ($disk->exists($cachePath)) {
                    $cached = $disk->get($cachePath);

                    if ($cached !== null && $cached !== '') {
                        return $cached;
                    }
                }
            } catch (Throwable $exception) {
                // Cache illeggibile: si rigenera. Non e' un fallback su dato
                // sintetico (ADR 0005), il PDF prodotto e' identico.
                report($exception);
            }
        }

        $bytes = $this->renderFresh($communication);

        if ($disk !== null) {
            try {
                // Il disco e' configurato con throw=false: un errore di scrittura
                // torna come `false` invece di sollevare. Va segnalato comunque,
                // altrimenti la cache resta muta e ogni richiesta rigenera.
                if ($disk->put($cachePath, $bytes) === false) {
                    report(new RuntimeException(
                        'Impossibile materializzare il PDF della comunicazione '.$communication->id.'.'
                    ));
                }
            } catch (Throwable $exception) {
                // La scrittura in cache non deve far fallire il download.
                report($exception);
            }
        }

        return $bytes;
    }

    /**
     * La cache e' un'ottimizzazione, non una dipendenza: se il disco non e'
     * configurato o non risponde, l'anteprima deve continuare a funzionare
     * rigenerando ogni volta. `Storage::disk()` solleva gia' in fase di
     * risoluzione (es. region S3 mancante), quindi il guard sta qui e non
     * intorno alle singole operazioni.
     */
    private function cacheDisk(): ?Filesystem
    {
        try {
            return Storage::disk($this->cacheDiskName());
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function cacheDiskName(): string
    {
        return (string) config('mvp.communications.pdf_disk', config('filesystems.default', 'local'));
    }

    private function cachePath(Communication $communication, string $fingerprint): string
    {
        $prefix = trim((string) config('mvp.communications.pdf_prefix', 'communications/exports'), '/');

        return $prefix.'/'.$communication->id.'/'.$fingerprint.'.pdf';
    }

    private function renderFresh(Communication $communication): string
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
