<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Tenancy\Events\TenantProvisioned;
use App\Modules\Tenancy\Listeners\QueueWelcomeNotification;
use App\Modules\Tenancy\Support\HostTenantResolver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;

/**
 * Tenancy wiring (spec §6). Tenant databases are created and migrated only by
 * ProvisionTenantDatabase (spec §9.4), never by a model event, so the
 * package's TenantCreated job pipeline is deliberately not registered.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DomainTenantResolver::class, HostTenantResolver::class);
    }

    public function boot(): void
    {
        Event::listen(Events\TenancyInitialized::class, Listeners\BootstrapTenancy::class);
        Event::listen(Events\TenancyEnded::class, Listeners\RevertToCentralContext::class);

        Event::listen(Events\TenancyBootstrapped::class, function (Events\TenancyBootstrapped $event): void {
            Context::add('tenant_id', $event->tenancy->tenant?->getTenantKey());
            $this->app->make(PermissionRegistrar::class)->clearPermissionsCollection();
        });

        Event::listen(Events\RevertedToCentralContext::class, function (): void {
            Context::forget('tenant_id');
            $this->app->make(PermissionRegistrar::class)->clearPermissionsCollection();
        });

        Event::listen(TenantProvisioned::class, QueueWelcomeNotification::class);

        InitializeTenancyByDomain::$onFail = static function (): never {
            abort(404);
        };

        InitializeTenancyByPath::$onFail = static function (): never {
            abort(404);
        };
    }
}
