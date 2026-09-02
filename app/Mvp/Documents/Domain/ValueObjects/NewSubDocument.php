<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

/**
 * Dati per creare un nuovo SubDocument (destinatario risultante da uno
 * split). Vedi NewOriginalDocument per il perche' di un costruttore
 * semplice invece della costruzione incrementale.
 */
final class NewSubDocument
{
    public function __construct(
        public readonly int $originalDocumentId,
        public readonly string $filePath,
        public readonly int $startPage,
        public readonly int $endPage,
    ) {}

    /**
     * @internal consumato solo dall'adapter di persistenza.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'original_document_id' => $this->originalDocumentId,
            'file_path' => $this->filePath,
            'start_page' => $this->startPage,
            'end_page' => $this->endPage,
        ];
    }
}
