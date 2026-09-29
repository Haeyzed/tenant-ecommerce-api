<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Jobs;

use App\Modules\Integrations\WooCommerce\Models\WooCommerceSettings;
use App\Modules\Integrations\WooCommerce\Services\WooCommerceSyncService;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Pushes the stock of every product marked dirty to WooCommerce in one
 * batch (spec §68.3). Debounced: dispatched 60 seconds after a stock
 * change and unique per tenant until it starts, so a burst of movements
 * becomes one push; changes during the push dispatch the next one.
 */
final class PushWooCommerceStock implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 900;

    public function __construct(public readonly string $tenantId)
    {
        $this->onQueue('tenant-default');
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(): void
    {
        Tenant::query()->find($this->tenantId)?->run(function (Tenant $tenant): void {
            $settings = WooCommerceSettings::query()->first();

            if ($settings === null || ! $settings->is_active || app(FeatureAccessService::class)->state($tenant, 'woocommerce') !== ModuleState::Enabled) {
                return;
            }

            app(WooCommerceSyncService::class)->pushDirtyStock();
        });
    }
}
