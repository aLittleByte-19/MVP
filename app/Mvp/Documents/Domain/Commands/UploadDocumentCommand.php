<?php

namespace App\Mvp\Documents\Domain\Commands;

use App\Mvp\Support\Identity\Actor;

/**
 * Input del caso d'uso di upload. `absoluteSourcePath` e' un percorso del
 * filesystem locale (dove PHP ha gia' scritto il file caricato prima che la
 * richiesta arrivasse qui): un concetto nativo, non di Illuminate — l'adapter
 * primario lo ricava da `UploadedFile::getRealPath()`. `Actor` e' obbligatorio:
 * il tenant del documento originale non puo' cadere su un default implicito.
 */
final class UploadDocumentCommand
{
    public function __construct(
        public readonly string $absoluteSourcePath,
        public readonly string $originalFilename,
        public readonly Actor $actor,
        public readonly ?string $manualDocumentType,
        public readonly ?string $manualCompanyName,
        public readonly ?int $manualReferenceMonth,
        public readonly ?int $manualReferenceYear,
        public readonly ?string $correlationId,
        public readonly ?string $requestId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function manualMetadata(): array
    {
        return [
            'document_type' => $this->manualDocumentType,
            'company_name' => $this->manualCompanyName,
            'reference_month' => $this->manualReferenceMonth,
            'reference_year' => $this->manualReferenceYear,
        ];
    }
}
