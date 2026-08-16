<?php

namespace App\Mvp\Support\Identity;

/**
 * Chi ha compiuto un'azione: id, tenant e ruoli, senza il contratto
 * Illuminate\Contracts\Auth\Authenticatable che App\Mvp\Identity\MvpUser deve
 * implementare per il middleware di autenticazione. Condiviso fra i due
 * domini come Clock/UniqueIdGenerator/TransactionManager (vedi ADR 0010):
 * "chi ha fatto cosa" non ha semantica specifica di un dominio. Tradotto da
 * MvpUser una sola volta, al bordo HTTP (vedi ResolvesActor) — da quel punto
 * in poi porte, eventi e comandi di dominio vedono solo questo, mai
 * Authenticatable.
 */
final class Actor
{
    /**
     * @param  array<int, string>  $roles
     */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $name,
        public readonly string $tenantId,
        public readonly array $roles,
    ) {}

    public function hasAnyRole(array $requiredRoles): bool
    {
        return array_intersect($this->roles, $requiredRoles) !== [];
    }
}
