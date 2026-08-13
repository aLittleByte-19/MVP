<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Mvp\Identity\MvpUser;
use App\Mvp\Support\Identity\Actor;
use Illuminate\Http\Request;

/**
 * L'identita' arriva dal middleware `mvp.identity`, che la costruisce prima di
 * ogni controller: se non e' un MvpUser il middleware non ha fatto il suo
 * lavoro, ed e' un errore di programmazione, non un 401 da esporre all'utente.
 * Punto unico di traduzione da MvpUser (Authenticatable, richiesto dal
 * middleware) ad Actor (di dominio, senza quel contratto): da qui in poi
 * controller, casi d'uso, porte ed eventi vedono solo Actor.
 */
trait ResolvesActor
{
    private function actor(Request $request): Actor
    {
        $user = $request->user();

        if (! $user instanceof MvpUser) {
            throw new \RuntimeException('MVP identity middleware did not provide a structured user.');
        }

        return $user->toActor();
    }
}
