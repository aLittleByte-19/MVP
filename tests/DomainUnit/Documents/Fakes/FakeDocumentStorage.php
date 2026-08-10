<?php

namespace Tests\DomainUnit\Documents\Fakes;

use App\Mvp\Documents\Domain\Ports\Outbound\DocumentStoragePort;

final class FakeDocumentStorage implements DocumentStoragePort
{
    /** @var array<string, string> */
    private array $files = [];

    /** @var list<string> */
    private array $deleted = [];

    public function storeFromLocalPath(string $absoluteSourcePath, string $directory): string
    {
        $path = trim($directory, '/').'/fake-'.substr(md5($absoluteSourcePath), 0, 12).'.pdf';
        $this->files[$path] = 'contenuto-fittizio';

        return $path;
    }

    public function read(string $path): string
    {
        return $this->files[$path] ?? throw new \RuntimeException("File non trovato: {$path}");
    }

    public function write(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    public function delete(string $path): void
    {
        $this->deleted[] = $path;
        unset($this->files[$path]);
    }

    public function has(string $path): bool
    {
        return isset($this->files[$path]);
    }

    /**
     * @return list<string>
     */
    public function deletedPaths(): array
    {
        return $this->deleted;
    }
}
