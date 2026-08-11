<?php

namespace App\Mvp\Communications\Adapters\Outbound\Storage;

use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Observability\MetricsRecorder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

class FlysystemCommunicationCoverAdapter implements CommunicationCoverStoragePort
{
    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function store(string $path, string $bytes): void
    {
        try {
            $stored = $this->disk()->put($path, $bytes);
        } catch (FilesystemException $e) {
            $stored = false;
            Log::error('Communication cover upload failed', ['path' => $path, 'message' => $e->getMessage()]);
        }

        if ($stored === false) {
            $this->metrics->recordDomainCounter('communication_cover_storage_failed_total', ['operation' => 'put']);

            throw new \RuntimeException('Storage copertine non raggiungibile.');
        }
    }

    public function delete(string $path): void
    {
        if ($path === '') {
            return;
        }

        try {
            $this->disk()->delete($path);
        } catch (FilesystemException $e) {
            $this->metrics->recordDomainCounter('communication_cover_storage_failed_total', ['operation' => 'delete']);
            Log::warning('Communication cover delete failed', ['path' => $path, 'message' => $e->getMessage()]);
        }
    }

    public function exists(string $path): bool
    {
        try {
            return $this->disk()->exists($path);
        } catch (FilesystemException $e) {
            $this->metrics->recordDomainCounter('communication_cover_storage_failed_total', ['operation' => 'exists']);

            throw new \RuntimeException("Storage copertine non raggiungibile: {$path}", previous: $e);
        }
    }

    public function read(string $path): string
    {
        try {
            $contents = $this->disk()->get($path);
        } catch (FilesystemException $e) {
            $contents = null;
        }

        if ($contents === null) {
            $this->metrics->recordDomainCounter('communication_cover_storage_failed_total', ['operation' => 'get']);

            throw new \RuntimeException("Copertina non trovata sullo storage: {$path}");
        }

        return $contents;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('mvp.communications.cover_disk', config('filesystems.default', 'local')));
    }
}
