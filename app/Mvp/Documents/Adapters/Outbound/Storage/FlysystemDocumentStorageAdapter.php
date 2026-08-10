<?php

namespace App\Mvp\Documents\Adapters\Outbound\Storage;

use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Adapter secondario: implementa {@see DocumentStoragePort} sopra il disco
 * Laravel/Flysystem configurato per i documenti (`mvp.documents.storage_disk`).
 */
class FlysystemDocumentStorageAdapter implements DocumentStoragePort
{
    public function storeFromLocalPath(string $absoluteSourcePath, string $directory): string
    {
        $extension = pathinfo($absoluteSourcePath, PATHINFO_EXTENSION);
        $relativePath = trim($directory, '/').'/'.Str::uuid().($extension !== '' ? '.'.$extension : '');

        $contents = File::get($absoluteSourcePath);

        if (! Storage::disk($this->disk())->put($relativePath, $contents)) {
            throw new \RuntimeException('Impossibile salvare il documento nello storage configurato.');
        }

        return $relativePath;
    }

    public function read(string $path): string
    {
        $contents = Storage::disk($this->disk())->get($path);

        if ($contents === null || $contents === false) {
            throw new \RuntimeException("File non trovato sullo storage documenti: {$path}");
        }

        return $contents;
    }

    public function write(string $path, string $contents): void
    {
        if (! Storage::disk($this->disk())->put($path, $contents)) {
            throw new \RuntimeException("Impossibile salvare il file sullo storage documenti: {$path}");
        }
    }

    public function delete(string $path): void
    {
        if ($path === '') {
            return;
        }

        Storage::disk($this->disk())->delete($path);
    }

    private function disk(): string
    {
        return (string) config('mvp.documents.storage_disk', config('filesystems.default', 'local'));
    }
}
