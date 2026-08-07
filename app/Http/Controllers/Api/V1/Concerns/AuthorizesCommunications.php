<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Communication;
use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Identity\MvpUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Guardie condivise sulle comunicazioni: proprieta' del tenant e precondizioni
 * di stato. Stanno insieme perche' ogni controller che tocca una bozza deve
 * applicarle nello stesso modo, e duplicarle e' il modo piu' facile per
 * lasciarne indietro una.
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

    /**
     * @throws ValidationException
     */
    private function assertCommunicationReadyForExport(Communication $communication): void
    {
        if (
            $communication->generation_status !== CommunicationGenerationStatus::Completed
            || $communication->status === CommunicationStatus::Discarded
        ) {
            throw ValidationException::withMessages([
                'communication' => ['La bozza non è ancora pronta per l\'anteprima/esportazione.'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertCommunicationIsEditable(Communication $communication): void
    {
        if ($communication->status === CommunicationStatus::Discarded) {
            throw ValidationException::withMessages([
                'communication' => ['Una bozza scartata non e\' modificabile.'],
            ]);
        }
    }

    private function assertCommunicationCanRegenerate(Communication $communication): void
    {
        abort_if(
            $communication->status === CommunicationStatus::Discarded,
            422,
            'Una bozza scartata non puo essere rigenerata.',
        );
        abort_unless(
            in_array($communication->generation_status, [
                CommunicationGenerationStatus::Completed,
                CommunicationGenerationStatus::Failed,
            ], true),
            409,
            'La rigenerazione e disponibile solo a generazione conclusa.',
        );
    }
}
