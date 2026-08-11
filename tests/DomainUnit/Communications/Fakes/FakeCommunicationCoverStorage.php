<?php

namespace Tests\DomainUnit\Communications\Fakes;

use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;

final class FakeCommunicationCoverStorage implements CommunicationCoverStoragePort
{
    /** @var array<string, string> */
    private array $stored = [];

    private ?\Throwable $storeException = null;

    /** @var list<string> */
    private array $deleted = [];

    public function willThrowOnStore(\Throwable $exception): void
    {
        $this->storeException = $exception;
    }

    public function store(string $path, string $bytes): void
    {
        if ($this->storeException !== null) {
            throw $this->storeException;
        }

        $this->stored[$path] = $bytes;
    }

    public function delete(string $path): void
    {
        $this->deleted[] = $path;
        unset($this->stored[$path]);
    }

    public function has(string $path): bool
    {
        return isset($this->stored[$path]);
    }

    public function exists(string $path): bool
    {
        return isset($this->stored[$path]);
    }

    public function read(string $path): string
    {
        return $this->stored[$path] ?? throw new \RuntimeException("Copertina non trovata: {$path}");
    }

    /**
     * @return list<string>
     */
    public function deletedPaths(): array
    {
        return $this->deleted;
    }
}
