<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Notifications\Support\NotificationCatalog;
use App\Modules\Plans\Services\PlanLimitService;
use App\Shared\Support\MorphMap;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationCatalog::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships(false);

        Relation::enforceMorphMap(MorphMap::MAP);

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        /*
         * CMS tables are shared with every tenant database (spec §24.1). Adding
         * the path here affects only the landlord "migrate" command:
         * tenants:migrate always runs with explicit --path values.
         */
        $this->loadMigrationsFrom(database_path('migrations/shared/cms'));

        Factory::guessFactoryNamesUsing(static function (string $modelName): string {
            $parts = explode('\\', $modelName);

            return 'Database\\Factories\\'.($parts[2] ?? 'App').'\\'.end($parts).'Factory';
        });

        $this->configureRouteParameters();
        $this->configureRateLimiting();

        // Channel definitions; the auth routes are mounted per domain in
        // routes/{landlord,tenant}/broadcasting.php (spec §72.4).
        require base_path('routes/channels.php');
    }

    /**
     * Numeric model parameters are constrained globally (spec §70.6).
     */
    private function configureRouteParameters(): void
    {
        foreach (MorphMap::NUMERIC_ROUTE_PARAMETERS as $parameter) {
            Route::pattern($parameter, '[0-9]+');
        }
    }

    /**
     * Named limiters (spec §70.5, Assumption A-63).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $user = Auth::user();

            return Limit::perMinute(120)->by($user !== null
                ? Auth::getDefaultDriver().':'.$user->getAuthIdentifier()
                : 'ip:'.$request->ip());
        });

        RateLimiter::for('public', fn (Request $request): Limit => Limit::perMinute(60)->by('ip:'.$request->ip()));

        RateLimiter::for('auth-sensitive', function (Request $request): Limit {
            // A phone counts by its digits, so formatting cannot dodge the limit.
            $identifier = $request->filled('email')
                ? strtolower((string) $request->input('email'))
                : (string) preg_replace('/\D+/', '', (string) $request->input('phone', ''));

            return Limit::perMinute(5)->by('auth:'.$request->ip().'|'.$identifier);
        });

        RateLimiter::for('affiliate-clicks', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('click:'.hash('sha256', (string) $request->ip())));

        RateLimiter::for('tenant', function (Request $request): Limit {
            $tenant = tenant();

            if ($tenant === null) {
                return Limit::none();
            }

            $limit = $this->app->make(PlanLimitService::class)->getLimit($tenant, 'max_api_requests_per_minute');

            return Limit::perMinute(max(1, $limit ?? 600))->by('tenant:'.$tenant->getTenantKey());
        });
    }
}
