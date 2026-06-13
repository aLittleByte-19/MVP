<?php

namespace App\Http\Middleware;

use App\Copilot\Identity\PocUser;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolvePocIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $identityMode = (string) config('poc.identity.mode', 'local');
        $claims = $identityMode === 'local'
            ? $this->localClaims()
            : $this->trustedHeaderClaims($request);

        $user = new PocUser(
            id: $claims['id'],
            email: $claims['email'],
            name: $claims['name'],
            tenantId: $claims['tenant_id'],
            roles: $claims['roles'],
        );

        Auth::setUser($user);
        $request->attributes->set('poc_user', $user);

        return $next($request);
    }

    /**
     * @return array{id: string, email: string, name: string, tenant_id: string, roles: array<int, string>}
     */
    private function localClaims(): array
    {
        $claims = config('poc.identity.local');

        return [
            'id' => (string) ($claims['id'] ?? 'poc-local-user'),
            'email' => (string) ($claims['email'] ?? 'operator@alittlebyte.local'),
            'name' => (string) ($claims['name'] ?? 'Alittlebyte Operator'),
            'tenant_id' => (string) ($claims['tenant_id'] ?? 'poc-local-tenant'),
            'roles' => array_values(array_filter($claims['roles'] ?? ['poc-operator'])),
        ];
    }

    /**
     * @return array{id: string, email: string, name: string, tenant_id: string, roles: array<int, string>}
     *
     * @throws AuthenticationException
     */
    private function trustedHeaderClaims(Request $request): array
    {
        $headers = config('poc.identity.trusted_headers');
        $id = trim((string) $request->headers->get($headers['id'] ?? 'X-Poc-User-Id'));
        $email = trim((string) $request->headers->get($headers['email'] ?? 'X-Poc-User-Email'));
        $name = trim((string) $request->headers->get($headers['name'] ?? 'X-Poc-User-Name'));
        $tenantId = trim((string) $request->headers->get($headers['tenant_id'] ?? 'X-Poc-Tenant-Id'));
        $roles = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $request->headers->get($headers['roles'] ?? 'X-Poc-Roles'))
        )));

        if ($id === '' || $email === '' || $tenantId === '' || $roles === []) {
            throw new AuthenticationException('Trusted identity claims are incomplete.');
        }

        return [
            'id' => $id,
            'email' => $email,
            'name' => $name !== '' ? $name : $email,
            'tenant_id' => $tenantId,
            'roles' => $roles,
        ];
    }
}
