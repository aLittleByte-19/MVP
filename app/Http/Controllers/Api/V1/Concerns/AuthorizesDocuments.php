<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Mvp\Support\Identity\Actor;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Il tenant e' l'unico confine di visibilita' sui documenti. Vive sul documento
 * originale: un sotto-documento senza originale non e' attribuibile ad alcun
 * tenant e va trattato come non autorizzato, non come accessibile.
 */
trait AuthorizesDocuments
{
    /**
     * @throws AuthorizationException
     */
    private function authorizeOriginalDocument(OriginalDocument $document, Actor $actor): void
    {
        if ($document->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Documento non autorizzato per il tenant corrente.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeSubDocument(SubDocument $subDocument, Actor $actor): void
    {
        if (! $subDocument->originalDocument || $subDocument->originalDocument->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Documento non autorizzato per il tenant corrente.');
        }
    }
}
