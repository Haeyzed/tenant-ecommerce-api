<?php

declare(strict_types=1);

use App\Shared\Exceptions\ExceptionRenderer;
use App\Shared\Http\Middleware\Landlord\AuthenticateEdgeRequest;
use App\Shared\Http\Middleware\Landlord\EnsureLandlordDomain;
use App\Shared\Http\Middleware\Shared\AuthenticateActor;
use App\Shared\Http\Middleware\Shared\AuthorizeDerivedPermission;
use App\Shared\Http\Middleware\Shared\EnforceIdempotency;
use App\Shared\Http\Middleware\Shared\ForceJsonResponse;
use App\Shared\Http\Middleware\Shared\HandleDynamicCors;
use App\Shared\Http\Middleware\Shared\SetRequestContext;
use App\Shared\Http\Middleware\Shared\VerifyWebhookSignature;
use App\Shared\Http\Middleware\Tenant\AuthenticateCustomerOptionally;
use App\Shared\Http\Middleware\Tenant\CheckModuleNotice;
use App\Shared\Http\Middleware\Tenant\EnsureFeatureEnabled;
use App\Shared\Http\Middleware\Tenant\EnsurePlatformNotInMaintenance;
use App\Shared\Http\Middleware\Tenant\EnsureTenantIsActive;
use App\Shared\Http\Middleware\Tenant\EnsureWithinUsageLimit;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        using: function (): void {
            $centralPattern = implode('|', array_map(
                static fn (string $domain): string => preg_quote($domain, '/'),
                (array) config('tenancy.central_domains'),
            ));

            /*
             * Landlord routes (spec §70.2): only on landlord domains. The domain
             * parameter is removed again by EnsureLandlordDomain.
             */
            Route::domain('{landlord_domain}')
                ->where(['landlord_domain' => $centralPattern])
                ->middleware(['landlord.domain', ForceJsonResponse::class])
                ->prefix('api')
                ->group(function (): void {
                    require base_path('routes/api.php');
                    require base_path('routes/tenant-webhooks.php');
                });

            /*
             * Tenant routes (spec §70.2): every other host. The tenant is
             * resolved from the host by InitializeTenancyByDomain.
             */
            Route::middleware([ForceJsonResponse::class])
                ->group(base_path('routes/tenant.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(HandleCors::class, HandleDynamicCors::class);

        $middleware->alias([
            'request.context' => SetRequestContext::class,
            'landlord.domain' => EnsureLandlordDomain::class,
            'auth.as' => AuthenticateActor::class,
            'auth.as.optional' => AuthenticateCustomerOptionally::class,
            'guest.token' => ResolveGuestToken::class,
            'platform.maintenance' => EnsurePlatformNotInMaintenance::class,
            'tenant.active' => EnsureTenantIsActive::class,
            'feature' => EnsureFeatureEnabled::class,
            'module.notice' => CheckModuleNotice::class,
            'usage.limit' => EnsureWithinUsageLimit::class,
            'permission' => PermissionMiddleware::class,
            'permission.derived' => AuthorizeDerivedPermission::class,
            'verify.webhook' => VerifyWebhookSignature::class,
            'idempotency' => EnforceIdempotency::class,
            'internal.edge' => AuthenticateEdgeRequest::class,
            'signed' => ValidateSignature::class,
        ]);

        $tenancy = [InitializeTenancyByDomain::class, PreventAccessFromCentralDomains::class];

        /*
         * Route groups (spec §70.3). Per-route middleware (feature,
         * module.notice, usage.limit, permission, idempotency) is appended
         * in the route files.
         */
        $middleware->group('landlord.public', [
            'request.context',
            'throttle:public',
            SubstituteBindings::class,
        ]);

        $middleware->group('landlord.admin', [
            'request.context',
            'throttle:api',
            'auth.as:platform',
            SubstituteBindings::class,
            'permission.derived',
        ]);

        $middleware->group('landlord.affiliate', [
            'request.context',
            'throttle:api',
            'auth.as:affiliate',
            SubstituteBindings::class,
        ]);

        $middleware->group('landlord.webhooks', [
            'request.context',
        ]);

        $middleware->group('landlord.internal', [
            'request.context',
            'internal.edge',
        ]);

        $middleware->group('tenant.public', [
            'request.context',
            ...$tenancy,
            'platform.maintenance',
            'tenant.active',
            'throttle:tenant',
            'throttle:public',
            SubstituteBindings::class,
        ]);

        $middleware->group('tenant.storefront', [
            'request.context',
            ...$tenancy,
            'platform.maintenance',
            'tenant.active',
            'throttle:tenant',
            'throttle:public',
            'auth.as.optional:customer',
            'guest.token',
            SubstituteBindings::class,
        ]);

        foreach (['customer', 'seller', 'driver'] as $actor) {
            $middleware->group("tenant.{$actor}", [
                'request.context',
                ...$tenancy,
                'platform.maintenance',
                'tenant.active',
                'throttle:tenant',
                'throttle:api',
                "auth.as:{$actor}",
                SubstituteBindings::class,
            ]);
        }

        $middleware->group('tenant.admin', [
            'request.context',
            ...$tenancy,
            'platform.maintenance',
            'tenant.active',
            'throttle:tenant',
            'throttle:api',
            'auth.as:staff',
            SubstituteBindings::class,
            'permission.derived',
        ]);

        $middleware->group('tenant.webhooks', [
            'request.context',
            InitializeTenancyByPath::class,
        ]);

        $middleware->priority([
            SetRequestContext::class,
            PreventAccessFromCentralDomains::class,
            InitializeTenancyByDomain::class,
            InitializeTenancyByPath::class,
            EnsurePlatformNotInMaintenance::class,
            EnsureTenantIsActive::class,
            AuthenticateActor::class,
            AuthenticateCustomerOptionally::class,
            ResolveGuestToken::class,
            SubstituteBindings::class,
            EnsureFeatureEnabled::class,
            CheckModuleNotice::class,
            EnsureWithinUsageLimit::class,
            PermissionMiddleware::class,
            AuthorizeDerivedPermission::class,
            EnforceIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => true);

        $exceptions->render(fn (Throwable $e, Request $request) => ExceptionRenderer::render($e, $request));

        $exceptions->dontReportDuplicates();
    })->create();
