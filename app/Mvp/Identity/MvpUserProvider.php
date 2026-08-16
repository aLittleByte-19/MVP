<?php

namespace App\Mvp\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

/**
 * `MvpUser` non ha un archivio persistente da cui essere recuperato: ogni
 * richiesta lo ricostruisce da zero da config (`local`) o da header fidati
 * (`trusted_headers`), vedi `ResolveMvpIdentity`, che lo imposta direttamente
 * con `Auth::setUser()` — mai tramite risoluzione del provider. Prima di
 * questo provider, `config/auth.php` dichiarava `driver: eloquent` con
 * `MvpUser` come model: `MvpUser` non estende `Illuminate\Database\Eloquent\Model`,
 * quindi qualunque chiamata che avesse davvero risolto quel provider
 * (`Auth::check()` prima che il middleware giri, il password broker) sarebbe
 * fallita con un errore fatale poco leggibile (metodo Eloquent inesistente).
 * Questo provider rende esplicito che quella risoluzione non e' supportata,
 * con un errore chiaro invece di uno criptico.
 */
class MvpUserProvider implements UserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): bool
    {
        throw $this->unsupported(__FUNCTION__);
    }

    private function unsupported(string $method): \LogicException
    {
        return new \LogicException(
            "MvpUserProvider::{$method}() non e' supportato: l'identita' MVP non ha un archivio persistente, ".
            "e' ricostruita ad ogni richiesta da ResolveMvpIdentity e impostata con Auth::setUser(), mai risolta ".
            'tramite un UserProvider. Se questo errore compare, qualcosa sta bypassando quel middleware.'
        );
    }
}
