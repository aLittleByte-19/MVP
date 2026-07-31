<?php

namespace App\Http\Controllers\Api\V1\Copilot\Concerns;

use App\Copilot\Identity\MvpUser;
use Illuminate\Http\Request;

/**
 * L'identita' arriva dal middleware `mvp.identity`, che la costruisce prima di
 * ogni controller: se non e' un MvpUser il middleware non ha fatto il suo
 * lavoro, ed e' un errore di programmazione, non un 401 da esporre all'utente.
 */
trait ResolvesActor
{
    private function actor(Request $request): MvpUser
    {
        $actor = $request->user();

        if (! $actor instanceof MvpUser) {
            throw new \RuntimeException('MVP identity middleware did not provide a structured user.');
        }

        return $actor;
    }
}
