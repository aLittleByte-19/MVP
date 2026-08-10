<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Communication;
use App\Mvp\Identity\MvpUser;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Guardia condivisa sulle comunicazioni: proprieta' del tenant. Le
 * precondizioni di stato (bozza scartata, non pronta per l'export, ecc.) sono
 * regole di dominio e vivono nei casi d'uso (vedi ADR 0010) — l'adapter HTTP
 * le traduce in risposte, non le valuta.
 */
trait AuthorizesCommunications
{
    /**
     * @throws AuthorizationException
     */
    private function assertCommunicationOwnership(Communication $communication, MvpUser $actor): void
    {
        if ($communication->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Communication is outside the authenticated tenant scope.');
        }
    }
}
