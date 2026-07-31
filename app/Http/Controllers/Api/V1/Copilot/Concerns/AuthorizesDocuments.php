<?php

namespace App\Http\Controllers\Api\V1\Copilot\Concerns;

use App\Copilot\Identity\MvpUser;
use App\Models\Copilot\OriginalDocument;
use App\Models\Copilot\SubDocument;
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
    private function authorizeOriginalDocument(OriginalDocument $document, MvpUser $actor): void
    {
        if ($document->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Documento non autorizzato per il tenant corrente.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeSubDocument(SubDocument $subDocument, MvpUser $actor): void
    {
        if (! $subDocument->originalDocument || $subDocument->originalDocument->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Documento non autorizzato per il tenant corrente.');
        }
    }
}
