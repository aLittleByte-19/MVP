<?php

namespace App\Mvp\Communications\Adapters\Outbound\Pdf;

use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationPdfRendererPort;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationPdfContext;
use App\Mvp\Support\PdfFooterStamper;
use App\Mvp\Support\PdfWatermarkStamper;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Throwable;

/**
 * Adapter secondario: implementa {@see CommunicationPdfRendererPort} sopra
 * Dompdf. La cache su disco per fingerprint resta un dettaglio implementativo
 * dell'adapter (ottimizzazione, non una dipendenza: vedi ADR 0005), non
 * esposta dalla porta.
 */
class DompdfCommunicationPdfRenderer implements CommunicationPdfRendererPort
{
    /** Il marcatore di trasparenza, in filigrana e nel piede di pagina. */
    private const ORIGIN = 'AI Assistant';

    private const WATERMARK_TEXT = 'Creato da AI Assistant';

    /**
     * Va incrementata a ogni modifica del template Blade, del watermark o del
     * pie' di pagina: entra nel fingerprint e invalida i PDF gia' materializzati.
     */
    private const RENDER_VERSION = 3;

    public function __construct(
        private readonly PdfFooterStamper $footerStamper,
        private readonly PdfWatermarkStamper $watermarkStamper,
    ) {}

    public function fingerprint(CommunicationPdfContext $context): string
    {
        return sha1(implode('|', [
            self::RENDER_VERSION,
            $context->id,
            (string) $context->title,
            (string) $context->body,
            $context->coverStatus,
            (string) $context->coverImagePath,
            (string) $context->coverImageMime,
        ]));
    }

    public function render(CommunicationPdfContext $context): string
    {
        $fingerprint = $this->fingerprint($context);
        $cachePath = $this->cachePath($context, $fingerprint);
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
                report($exception);
            }
        }

        $bytes = $this->renderFresh($context);

        if ($disk !== null) {
            try {
                if ($disk->put($cachePath, $bytes) === false) {
                    report(new RuntimeException('Impossibile materializzare il PDF della comunicazione '.$context->id.'.'));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $bytes;
    }

    public function filename(CommunicationPdfContext $context): string
    {
        $slug = Str::slug((string) $context->title);

        return ($slug !== '' ? $slug : 'comunicazione-'.$context->id).'.pdf';
    }

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

    private function cachePath(CommunicationPdfContext $context, string $fingerprint): string
    {
        $prefix = trim((string) config('mvp.communications.pdf_prefix', 'communications/exports'), '/');

        return $prefix.'/'.$context->id.'/'.$fingerprint.'.pdf';
    }

    private function renderFresh(CommunicationPdfContext $context): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('copilot.communications.pdf', [
            'title' => $context->title,
            'body' => $context->body,
            'coverDataUri' => $this->coverDataUri($context),
        ])->render());
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        $this->watermarkStamper->stamp($dompdf, self::WATERMARK_TEXT);
        $this->footerStamper->stamp($dompdf, self::ORIGIN);

        return $dompdf->output();
    }

    private function coverDataUri(CommunicationPdfContext $context): ?string
    {
        if ($context->coverStatus !== CoverImageStatus::Ready->value || ! $context->coverImagePath) {
            return null;
        }

        $disk = Storage::disk((string) config('mvp.communications.cover_disk', config('filesystems.default', 'local')));

        try {
            if (! $disk->exists($context->coverImagePath)) {
                return null;
            }

            $bytes = $disk->get($context->coverImagePath);
        } catch (FilesystemException) {
            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        return 'data:'.($context->coverImageMime ?: 'image/png').';base64,'.base64_encode($bytes);
    }
}
