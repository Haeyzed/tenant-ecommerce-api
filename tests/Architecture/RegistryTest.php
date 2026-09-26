<?php

declare(strict_types=1);

use App\Modules\Access\Support\RoutePermissions;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Support\NotificationCatalog;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Shared\Support\MorphMap;
use OwenIt\Auditing\Contracts\Auditable;
use Symfony\Component\Finder\Finder;

it('has a consistent module registry: known requirements, no cycles, valid lifecycles', function (): void {
    $problems = array_filter(
        app(ModuleRegistry::class)->problems(),
        static fn (string $problem): bool => ! str_contains($problem, 'does not exist'),
    );

    expect($problems)->toBe([]);
});

it('has a code module folder for every registry key', function (): void {
    expect(app(ModuleRegistry::class)->problems())->toBe([]);
})->skip('Optional module folders are created as build steps 17-28 are implemented.');

it('has a consistent notification catalog', function (): void {
    expect(app(NotificationCatalog::class)->problems())->toBe([]);
});

it('dispatches only catalog notification keys', function (): void {
    $catalog = app(NotificationCatalog::class);
    $unknown = [];

    foreach (Finder::create()->in([app_path(), database_path('seeders')])->name('*.php')->files() as $file) {
        preg_match_all("/->dispatch\\(\\s*'([a-z_]+\\.[a-z_.]+)'/", $file->getContents(), $matches);

        foreach ($matches[1] as $key) {
            if (! $catalog->has($key, NotificationScope::Landlord) && ! $catalog->has($key, NotificationScope::Tenant)) {
                $unknown[] = $file->getRelativePathname().': '.$key;
            }
        }
    }

    expect($unknown)->toBe([]);
});

it('uses only registered limit keys in usage.limit middleware', function (): void {
    $limits = array_keys((array) config('limits'));
    $unknown = [];

    foreach (app('router')->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'usage.limit:')) {
                $key = substr($middleware, strlen('usage.limit:'));

                if (! in_array($key, $limits, true)) {
                    $unknown[] = $route->uri().': '.$key;
                }
            }
        }
    }

    expect($unknown)->toBe([]);
});

it('uses only registry keys in feature middleware', function (): void {
    $registry = app(ModuleRegistry::class);
    $unknown = [];

    foreach (app('router')->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/^(feature|module\.notice):([a-z_]+)/', $middleware, $m) && ! $registry->has($m[2])) {
                $unknown[] = $route->uri().': '.$m[2];
            }
        }
    }

    expect($unknown)->toBe([]);
});

it('keeps the generated permission catalogues in sync with the routes', function (string $context): void {
    $generated = require config_path("permissions/generated/{$context}.php");

    expect($generated)->toEqualCanonicalizing(app(RoutePermissions::class)->derive($context));
})->with(['landlord', 'tenant']);

it('registers every audited model in the morph map', function (): void {
    $missing = [];

    foreach (Finder::create()->in(app_path('Modules'))->path('Models')->name('*.php')->files() as $file) {
        $class = 'App\\Modules\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (class_exists($class) && is_subclass_of($class, Auditable::class)
            && ! in_array($class, MorphMap::MAP, true)) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([]);
});

it('keeps notification keys unique across scopes', function (): void {
    $landlord = array_keys(config('notifications.landlord'));
    $tenant = array_keys(config('notifications.tenant'));

    expect(array_intersect($landlord, $tenant))->toBe([]);
});
