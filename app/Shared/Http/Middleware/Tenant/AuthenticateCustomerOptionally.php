<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Tenant;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\HasApiTokens;
use Symfony\Component\HttpFoundation\Response;

/**
 * auth.as.optional:customer (spec §10.2). Resolves a customer when a valid
 * customer token is present; an absent or invalid token continues as a guest.
 */
final class AuthenticateCustomerOptionally
{
    public function handle(Request $request, Closure $next, string $actor = 'customer'): Response
    {
        if ($request->bearerToken() !== null) {
            $user = Auth::guard($actor)->user();

            $hasTokens = $user !== null && in_array(HasApiTokens::class, class_uses_recursive($user), true);

            if ($hasTokens && $user->tokenCan($actor) && ! $user->tokenCan('*')) {
                Auth::shouldUse($actor);
                Context::add('actor', $actor.':'.$user->getAuthIdentifier());
            } else {
                Auth::guard($actor)->forgetUser();
            }
        }

        return $next($request);
    }
}
