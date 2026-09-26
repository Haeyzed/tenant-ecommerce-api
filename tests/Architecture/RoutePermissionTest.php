<?php

declare(strict_types=1);

use App\Modules\Access\Support\PermissionName;

it('derives a permission for every admin route that requires one', function (): void {
    $underivable = [];

    foreach (app('router')->getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $isAdmin = in_array('landlord.admin', $middleware, true) || in_array('tenant.admin', $middleware, true);

        if ($isAdmin && ! in_array('permission.derived', $route->excludedMiddleware(), true) && PermissionName::forRoute($route) === null) {
            $underivable[] = implode('|', $route->methods()).' '.$route->uri().' @'.$route->getActionMethod();
        }
    }

    expect($underivable)->toBe([]);
});
