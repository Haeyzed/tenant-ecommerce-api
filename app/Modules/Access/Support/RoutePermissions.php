<?php

declare(strict_types=1);

namespace App\Modules\Access\Support;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Lists the permissions derived from the registered admin routes (§12.4).
 */
final class RoutePermissions
{
    /**
     * Route default of a self-service route: the permission required to act
     * on someone else's record (e.g. hr.attendance.record).
     */
    public const string ACTING_PERMISSION = 'acting_permission';

    public function __construct(private readonly Router $router) {}

    /**
     * @return list<string>
     */
    public function derive(string $context): array
    {
        $names = [];

        foreach ($this->routes($context) as $route) {
            $name = PermissionName::forRoute($route);

            if ($name !== null) {
                $names[$name] = true;
            }
        }

        // Self-service routes need no permission for the user's own record,
        // but name the one that lets staff act for someone else (§12.4).
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (in_array($context.'.admin', $route->middleware(), true) && isset($route->defaults[self::ACTING_PERMISSION])) {
                $names[(string) $route->defaults[self::ACTING_PERMISSION]] = true;
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * Admin routes of a context that require a derived permission.
     *
     * @return list<Route>
     */
    public function routes(string $context): array
    {
        $group = $context.'.admin';

        return array_values(array_filter(
            $this->router->getRoutes()->getRoutes(),
            static fn (Route $route): bool => in_array($group, $route->middleware(), true)
                && ! in_array('permission.derived', $route->excludedMiddleware(), true),
        ));
    }
}
