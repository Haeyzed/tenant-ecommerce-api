<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Cms\Support\CmsLinkResolver;
use App\Modules\Cms\Support\CmsSitemapSource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\CustomFields\Models\CustomFieldDefinition;
use App\Modules\CustomFields\Services\CustomFieldService;
use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Exports\Support\ExportDefinition;
use App\Modules\Exports\Support\ExportRegistry;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Plans\Support\ModuleRegistry;
use App\Modules\Seo\Support\SitemapBuilder;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Services\TenantUsageReporter;
use App\Modules\Users\Models\User;
use App\Modules\Users\Support\StaffAccessScope;
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
        $this->app->singleton(ExportRegistry::class, function (): ExportRegistry {
            $registry = new ExportRegistry;

            // A customer's own personal data (§26.4): requested by the customer
            // or by staff on their behalf; the link always goes to the customer.
            $registry->register(new ExportDefinition(
                type: CustomerService::EXPORT_TYPE,
                label: 'Personal data',
                rules: ['customer_id' => ['required', 'integer']],
                columns: ['section' => 'Section', 'record' => 'Record'],
                rows: fn (array $parameters): iterable => $this->app->make(CustomerPrivacyRegistry::class)
                    ->export(Customer::query()->findOrFail((int) $parameters['customer_id'])),
                formats: ['json'],
            ));

            return $registry;
        });

        // Personal-data erasure and export hooks (§26.4); each module holding
        // customer data adds its own as it is built.
        $this->app->singleton(CustomerPrivacyRegistry::class, static function (): CustomerPrivacyRegistry {
            $registry = new CustomerPrivacyRegistry;

            $registry->registerEraser('addresses', static fn (Customer $c) => $c->addresses()->delete());
            $registry->registerEraser('notification_preferences', static fn (Customer $c) => DB::connection('tenant')->table('notification_preferences')
                ->where('notifiable_type', $c->getMorphClass())->where('notifiable_id', $c->id)->delete());
            $registry->registerEraser('notifications', static fn (Customer $c) => $c->notifications()->delete());
            $registry->registerEraser('custom_fields', static fn (Customer $c) => app(CustomFieldService::class)->forget($c, CustomerService::ENTITY));

            $registry->registerSection('account', static fn (Customer $c): iterable => [[
                'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone,
                'email_verified_at' => $c->email_verified_at?->toIso8601String(),
                'customer_group' => $c->group?->name, 'created_at' => $c->created_at->toIso8601String(),
                'last_login_at' => $c->last_login_at?->toIso8601String(),
            ]]);
            $registry->registerSection('addresses', static fn (Customer $c): iterable => $c->addresses()->get()
                ->map(static fn ($a): array => $a->only(['label', 'recipient_name', 'phone', 'address_line_1', 'address_line_2', 'city_id', 'state_id', 'country_id', 'postal_code', 'is_default']))
                ->all());

            return $registry;
        });

        // Staff data-access scope (§25.3); Inventory registers the warehouse
        // assignment resolver when warehouses are built.
        $this->app->singleton(StaffAccessScope::class);

        // Daily snapshot figures (orders, gross sales, failed postings) are
        // contributed by the modules that own their tables (spec §22.6).
        $this->app->singleton(TenantUsageReporter::class);

        // Entities accepting custom fields are registered by the module that
        // owns each entity's table, as the module is built (spec §23.1).
        $this->app->singleton(CustomFieldEntityRegistry::class, static function ($app): CustomFieldEntityRegistry {
            $registry = new CustomFieldEntityRegistry($app->make(ModuleRegistry::class), $app->make(FeatureAccessService::class));
            $registry->register('customer', Customer::class, 'customers');

            return $registry;
        });

        // Scoped: its per-request value cache must never cross tenants.
        $this->app->scoped(CustomFieldService::class);

        // Catalogue link types (category, brand, product) register their
        // resolvers here when the catalogue module is built (spec §24.3).
        $this->app->singleton(CmsLinkResolver::class);

        // Sitemap sources per scope; the catalogue adds products (§30.2).
        $this->app->singleton(SitemapBuilder::class, static function (): SitemapBuilder {
            $builder = new SitemapBuilder;
            $builder->register('tenant', 'cms', static fn (): iterable => CmsSitemapSource::entries());
            $builder->register('landlord', 'cms', static fn (): iterable => CmsSitemapSource::entries());

            return $builder;
        });

        $this->app->singleton(UsageCounterRegistry::class, static function (): UsageCounterRegistry {
            $registry = new UsageCounterRegistry;

            // Core counters. Each code module adds its own counter here when
            // it introduces the counted table (spec §11.10).
            $registry->register('max_users', static fn (): int => User::query()->where('is_active', true)->count());
            $registry->register('max_storage_mb', static fn (): int => (int) ceil(
                (int) DB::connection('tenant')->table('media')->sum('size') / 1048576,
            ));
            $registry->register('max_custom_fields', static fn (): int => CustomFieldDefinition::query()->where('is_active', true)->count());
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
