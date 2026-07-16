<?php

namespace App\Http\Middleware;

use App\Copilot\Identity\MvpUser;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ResolveMvpIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $identityMode = (string) config('mvp.identity.mode', 'local');
        $claims = $identityMode === 'local'
            ? $this->localClaims()
            : $this->trustedHeaderClaims($request);

        $user = new MvpUser(
            id: $claims['id'],
            email: $claims['email'],
            name: $claims['name'],
            tenantId: $claims['tenant_id'],
            roles: $claims['roles'],
        );

        Auth::setUser($user);
        $request->attributes->set('mvp_user', $user);

        return $next($request);
    }

    /**
     * @return array{id: string, email: string, name: string, tenant_id: string, roles: array<int, string>}
     */
    private function localClaims(): array
    {
        $claims = config('mvp.identity.local');

        return [
            'id' => (string) ($claims['id'] ?? 'mvp-local-user'),
            'email' => (string) ($claims['email'] ?? 'operator@alittlebyte.local'),
            'name' => (string) ($claims['name'] ?? 'Alittlebyte Operator'),
            'tenant_id' => (string) ($claims['tenant_id'] ?? 'mvp-local-tenant'),
            'roles' => array_values(array_filter($claims['roles'] ?? ['mvp-operator'])),
        ];
    }

    /**
     * @return array{id: string, email: string, name: string, tenant_id: string, roles: array<int, string>}
     *
     * @throws AuthenticationException
     */
    private function trustedHeaderClaims(Request $request): array
    {
        $headers = config('mvp.identity.trusted_headers');
        $id = trim((string) $request->headers->get($headers['id'] ?? 'X-Mvp-User-Id'));
        $email = trim((string) $request->headers->get($headers['email'] ?? 'X-Mvp-User-Email'));
        $name = trim((string) $request->headers->get($headers['name'] ?? 'X-Mvp-User-Name'));
        $tenantId = trim((string) $request->headers->get($headers['tenant_id'] ?? 'X-Mvp-Tenant-Id'));
        $roles = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $request->headers->get($headers['roles'] ?? 'X-Mvp-Roles'))
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
