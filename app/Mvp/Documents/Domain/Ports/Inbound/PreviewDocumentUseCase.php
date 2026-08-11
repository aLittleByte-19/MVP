<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Documents\Domain\Exceptions\DocumentPreviewUnavailableException;
use App\Mvp\Documents\Domain\ValueObjects\PreviewableDocument;

/**
 * Porta primaria: anteprima del PDF di un sotto-documento.
 */
interface PreviewDocumentUseCase
{
    /**
     * @throws DocumentPreviewUnavailableException se il file non e' sullo storage.
     */
    public function preview(int $subDocumentId): PreviewableDocument;
}
