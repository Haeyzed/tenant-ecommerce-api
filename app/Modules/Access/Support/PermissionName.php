<?php

declare(strict_types=1);

namespace App\Modules\Access\Support;

use Illuminate\Routing\Route;

/**
 * Derives a route's permission name mechanically (spec §12.4):
 *
 * 1. take the URI after /api/admin/;
 * 2. remove every {parameter} segment;
 * 3. index, show and metrics map to "view", store to "create", update to
 *    "update", destroy to "delete"; any other action uses the last
 *    remaining segment as the ability (a "metrics" segment is dropped);
 * 4. the remaining segments joined with "." form the resource.
 */
final class PermissionName
{
    private const array STANDARD = [
        'index' => 'view',
        'show' => 'view',
        'metrics' => 'view',
        'store' => 'create',
        'update' => 'update',
        'destroy' => 'delete',
    ];

    public static function forRoute(Route $route): ?string
    {
        return self::derive($route->uri(), $route->getActionMethod());
    }

    public static function derive(string $uri, string $action): ?string
    {
        $uri = trim($uri, '/');
        $prefix = 'api/admin/';

        if (! str_starts_with($uri, $prefix)) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', substr($uri, strlen($prefix))),
            static fn (string $segment): bool => $segment !== '' && ! str_starts_with($segment, '{'),
        ));

        if (isset(self::STANDARD[$action])) {
            if ($action === 'metrics' && end($segments) === 'metrics') {
                array_pop($segments);
            }

            $ability = self::STANDARD[$action];
        } else {
            $ability = (string) array_pop($segments);
        }

        if ($segments === [] || $ability === '') {
            return null;
        }

        return implode('.', $segments).'.'.$ability;
    }
}
