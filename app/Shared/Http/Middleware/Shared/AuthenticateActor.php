<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Shared;

use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\HasApiTokens;
use Symfony\Component\HttpFoundation\Response;

/**
 * auth.as:{actor} (spec §10.2, §71). Authenticates the bearer token with the
 * actor's Sanctum guard, whose provider only accepts that actor's model, and
 * requires the token's single ability to equal the actor type. A token issued
 * to one actor type is therefore rejected on every other actor's routes.
 */
final class AuthenticateActor
{
    public function handle(Request $request, Closure $next, string $actor): Response
    {
        $user = Auth::guard($actor)->user();

        $hasTokens = $user !== null && in_array(HasApiTokens::class, class_uses_recursive($user), true);

        if (! $hasTokens || ! $user->tokenCan($actor) || $user->tokenCan('*')) {
            throw new ApiException('unauthenticated', 'Unauthenticated.', 401);
        }

        Auth::shouldUse($actor);

        Context::add('actor', $actor.':'.$user->getAuthIdentifier());

        return $next($request);
    }
}
