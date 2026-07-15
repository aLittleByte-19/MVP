<?php

namespace App\Http\Middleware;

use App\Copilot\Identity\MvpUser;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeMvpAccess
{
    /**
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var mixed $user */
        $user = $request->user();

        if (! $user instanceof MvpUser) {
            throw new AuthenticationException('MVP identity is missing.');
        }

        if ($user->tenantId === '') {
            throw new AuthorizationException('MVP tenant claim is required.');
        }

        $allowedRoles = config('mvp.authorization.roles', ['mvp-operator', 'mvp-admin']);

        if (! $user->hasAnyRole($allowedRoles)) {
            throw new AuthorizationException('MVP role is not authorized.');
        }

        return $next($request);
    }
}
