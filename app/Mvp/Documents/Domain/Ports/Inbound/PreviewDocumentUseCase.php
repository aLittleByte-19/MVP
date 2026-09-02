<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Documents\Domain\Exceptions\DocumentNotAuthorizedException;
use App\Mvp\Documents\Domain\Exceptions\DocumentPreviewUnavailableException;
use App\Mvp\Documents\Domain\ValueObjects\PreviewableDocument;
use App\Mvp\Support\Identity\Actor;

/**
 * Porta primaria: anteprima del PDF di un sotto-documento.
 */
interface PreviewDocumentUseCase
{
    /**
     * @throws DocumentPreviewUnavailableException se il file non e' sullo storage.
     * @throws DocumentNotAuthorizedException se il sotto-documento non appartiene al tenant dell'attore.
     */
    public function preview(int $subDocumentId, Actor $actor): PreviewableDocument;

    /**
     * Anteprima del documento originale completo (non splittato), risalendo
     * dal sotto-documento al suo OriginalDocument (UC-40.2/RF56-OB).
     *
     * @throws DocumentPreviewUnavailableException se il file non e' sullo storage.
     * @throws DocumentNotAuthorizedException se il documento non appartiene al tenant dell'attore.
     */
    public function previewOriginal(int $subDocumentId, Actor $actor): PreviewableDocument;
}
