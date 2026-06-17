<?php

namespace App\Http\Middleware;

use App\Copilot\Identity\PocUser;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizePocAccess
{
    /**
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var mixed $user */
        $user = $request->user();

        if (! $user instanceof PocUser) {
            throw new AuthenticationException('PoC identity is missing.');
        }

        if ($user->tenantId === '') {
            throw new AuthorizationException('PoC tenant claim is required.');
        }

        $allowedRoles = config('poc.authorization.roles', ['poc-operator', 'poc-admin']);

        if (! $user->hasAnyRole($allowedRoles)) {
            throw new AuthorizationException('PoC role is not authorized.');
        }

        return $next($request);
    }
}
