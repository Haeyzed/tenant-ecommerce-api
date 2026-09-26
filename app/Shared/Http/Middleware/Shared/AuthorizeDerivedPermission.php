<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Shared;

use App\Modules\Access\Support\PermissionName;
use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpFoundation\Response;

/**
 * permission (spec §12.4, §71). Every landlord.admin and tenant.admin route
 * requires the permission derived from its URI and action, on the current
 * actor's guard. Routes marked "none" in the spec opt out with
 * ->withoutMiddleware('permission.derived').
 */
final class AuthorizeDerivedPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $permission = $route !== null ? PermissionName::forRoute($route) : null;
        $user = Auth::user();

        if ($permission === null || $user === null || ! method_exists($user, 'hasPermissionTo')) {
            throw ApiException::forbidden('forbidden', 'You are not allowed to perform this action.');
        }

        try {
            $allowed = $user->hasPermissionTo($permission, Auth::getDefaultDriver());
        } catch (PermissionDoesNotExist) {
            $allowed = false;
        }

        if (! $allowed) {
            throw ApiException::forbidden('forbidden', 'You are not allowed to perform this action.', ['permission' => $permission]);
        }

        return $next($request);
    }
}
