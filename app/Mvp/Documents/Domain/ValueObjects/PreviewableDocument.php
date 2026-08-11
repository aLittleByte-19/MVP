<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

/**
 * Contenuto binario di un sotto-documento pronto per essere restituito in
 * anteprima. Nessun riferimento a Storage/Flysystem.
 */
final class PreviewableDocument
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $filename,
    ) {}
}
