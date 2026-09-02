<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

/**
 * Contenuto binario di una copertina pronto per essere restituito in
 * download/anteprima. Nessun riferimento a Storage/Flysystem.
 */
final class DownloadableCoverImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
    ) {}
}
