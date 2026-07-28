<?php

namespace App\Copilot\Communications\Services;

use App\Copilot\Communications\Enums\CoverImageSource;
use App\Copilot\Communications\Enums\CoverImageStatus;
use App\Copilot\Observability\MetricsRecorder;
use App\Models\Copilot\Communication;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;

/**
 * Cover storage for generated communications: the image lives on the document
 * storage, the row only keeps the disk-relative path.
 */
class CommunicationCoverService
{
    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly MetricsRecorder $metrics,
    ) {}

    public function storeGenerated(Communication $communication, string $bytes, string $mime): void
    {
        $this->store($communication, $bytes, $mime, CoverImageSource::Ai);
    }

    public function storeUploaded(Communication $communication, UploadedFile $file): void
    {
        $path = $file->getRealPath();
        $contents = $path !== false ? file_get_contents($path) : false;

        if ($contents === false || $contents === '') {
            throw new \RuntimeException('Immagine di copertina non leggibile.');
        }

        $this->store(
            $communication,
            $contents,
            $file->getMimeType() ?: 'image/png',
            CoverImageSource::Manual,
        );
    }

    public function remove(Communication $communication): void
    {
        $previousPath = $communication->cover_image_path;

        $communication->update([
            'cover_image_path' => null,
            'cover_image_mime' => null,
            'cover_image_size' => null,
            'cover_image_source' => null,
            'cover_status' => CoverImageStatus::Removed,
            'cover_error' => null,
        ]);

        $this->deleteObject($previousPath);
    }

    /**
     * Scarta la copertina precedente in vista di una rigenerazione: a
     * differenza di remove() non tocca cover_status, che viene riportato a
     * Pending da CommunicationWorkflowService::start() insieme al resto della
     * pipeline.
     */
    public function discardForRegeneration(Communication $communication): void
    {
        $previousPath = $communication->cover_image_path;

        $communication->update([
            'cover_image_path' => null,
            'cover_image_mime' => null,
            'cover_image_size' => null,
            'cover_image_source' => null,
            'cover_error' => null,
        ]);

        $this->deleteObject($previousPath);
    }

    public function markFailed(Communication $communication, string $warning, string $reason): void
    {
        $communication->update([
            'cover_status' => CoverImageStatus::Failed,
            'cover_error' => $warning,
        ]);

        $this->metrics->recordDomainCounter('communication_covers_failed_total', ['reason' => $reason]);
    }

    public function markProcessing(Communication $communication): void
    {
        $communication->update([
            'cover_status' => CoverImageStatus::Processing,
            'cover_error' => null,
        ]);
    }

    private function store(Communication $communication, string $bytes, string $mime, CoverImageSource $source): void
    {
        $previousPath = $communication->cover_image_path;
        // Chiave nuova a ogni scrittura: sostituire la copertina non deve
        // lasciare in cache al browser quella precedente.
        $path = sprintf(
            '%s/%d/%s.%s',
            trim((string) config('mvp.communications.cover_prefix', 'communications/covers'), '/'),
            $communication->id,
            Str::uuid()->toString(),
            self::EXTENSIONS[strtolower($mime)] ?? 'png',
        );

        try {
            $stored = $this->disk()->put($path, $bytes);
        } catch (FilesystemException $e) {
            $stored = false;
            Log::error('Communication cover upload failed', [
                'communication_id' => $communication->id,
                'message' => $e->getMessage(),
            ]);
        }

        if ($stored === false) {
            $this->metrics->recordDomainCounter('communication_cover_storage_failed_total', ['operation' => 'put']);

            throw new \RuntimeException('Storage copertine non raggiungibile.');
        }

        $communication->update([
            'cover_image_path' => $path,
            'cover_image_mime' => $mime,
            'cover_image_size' => strlen($bytes),
            'cover_image_source' => $source,
            'cover_status' => CoverImageStatus::Ready,
            'cover_error' => null,
        ]);

        $this->metrics->recordDomainCounter('communication_covers_generated_total', ['source' => $source->value]);
        $this->deleteObject($previousPath);
    }

    /**
     * Un oggetto orfano non deve far fallire l'operazione dell'operatore: viene
     * contato come errore di storage e lasciato al ciclo di pulizia.
     */
    private function deleteObject(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            $this->disk()->delete($path);
        } catch (FilesystemException $e) {
            $this->metrics->recordDomainCounter('communication_cover_storage_failed_total', ['operation' => 'delete']);
            Log::warning('Communication cover delete failed', [
                'path' => $path,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config(
            'mvp.communications.cover_disk',
            config('filesystems.default', 'local'),
        ));
    }
}
