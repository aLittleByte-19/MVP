<?php

namespace App\Copilot\Identity;

use Illuminate\Contracts\Auth\Authenticatable;

class PocUser implements Authenticatable
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

    public function getAuthIdentifierName(): string
    {
        return 'poc_user_id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'poc_password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }

    public function hasAnyRole(array $requiredRoles): bool
    {
        return array_intersect($this->roles, $requiredRoles) !== [];
    }
}
