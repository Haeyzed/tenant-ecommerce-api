<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Exports\Support\ExportRegistry;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Services\TenantUsageReporter;
use App\Modules\Users\Models\User;
use App\Shared\Support\UsageCounterRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Wires everything that comes from the module registry (spec §73.5): the
 * registry itself, usage counters and, outside production, registry
 * validation at boot. Never checks tenant state.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);

        // Export types are registered here by each module that owns data
        // worth exporting (spec §19.4), as the module is built.
        $this->app->singleton(ExportRegistry::class);

        // Daily snapshot figures (orders, gross sales, failed postings) are
        // contributed by the modules that own their tables (spec §22.6).
        $this->app->singleton(TenantUsageReporter::class);

        $this->app->singleton(UsageCounterRegistry::class, static function (): UsageCounterRegistry {
            $registry = new UsageCounterRegistry;

            // Core counters. Each code module adds its own counter here when
            // it introduces the counted table (spec §11.10).
            $registry->register('max_users', static fn (): int => User::query()->where('is_active', true)->count());
            $registry->register('max_storage_mb', static fn (): int => (int) ceil(
                (int) DB::connection('tenant')->table('media')->sum('size') / 1048576,
            ));
            $registry->register('max_custom_domains', static fn (): int => Domain::query()
                ->where('tenant_id', tenant()?->getTenantKey())
                ->where('type', 'custom')
                ->count());

            return $registry;
        });
    }

    public function boot(): void
    {
        if ($this->app->isProduction()) {
            return;
        }

        // Missing code-module folders are reported by the architecture tests
        // instead: optional modules are added build step by build step.
        $problems = array_filter(
            $this->app->make(ModuleRegistry::class)->problems(),
            static fn (string $problem): bool => ! str_contains($problem, 'does not exist'),
        );

        if ($problems !== []) {
            throw new RuntimeException('Invalid config/modules.php: '.implode(' ', $problems));
        }
    }
}
