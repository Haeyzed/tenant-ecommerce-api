<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce;

use App\Modules\Integrations\WooCommerce\Jobs\PushWooCommerceStock;
use App\Modules\Integrations\WooCommerce\Models\WooCommerceSettings;
use App\Modules\Integrations\WooCommerce\Services\WooCommerceSyncService;
use App\Modules\Inventory\Events\StockChanged;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The stock hook (§68.3): every committed stock change of a mapped
 * product marks it dirty and queues a debounced push. Nothing happens
 * without the woocommerce feature or while sync is off.
 */
final class WooCommerceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(StockChanged::class, function (StockChanged $event): void {
            $tenant = tenant();

            if (! $tenant instanceof Tenant || (string) $tenant->getTenantKey() !== $event->tenantId
                || $this->app->make(FeatureAccessService::class)->state($tenant, 'woocommerce') !== ModuleState::Enabled
                || WooCommerceSettings::query()->where('is_active', true)->doesntExist()) {
                return;
            }

            if ($this->app->make(WooCommerceSyncService::class)->markStockDirty($event->productIds) > 0) {
                PushWooCommerceStock::dispatch($event->tenantId)->delay(now()->addSeconds(60));
            }
        });
    }
}
