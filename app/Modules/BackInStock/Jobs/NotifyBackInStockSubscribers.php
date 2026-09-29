<?php

declare(strict_types=1);

namespace App\Modules\BackInStock\Jobs;

use App\Modules\BackInStock\Services\BackInStockService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Raised by StockReplenished (spec §56): alerts everyone waiting for the
 * item, off the request that restocked it. Nothing is sent while the
 * capability is not enabled; the requests keep waiting.
 */
final class NotifyBackInStockSubscribers implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly string $tenantId,
        public readonly int $productId,
        public readonly ?int $variantId,
    ) {
        $this->onQueue('tenant-default');
    }

    public function handle(): void
    {
        Tenant::query()->find($this->tenantId)?->run(function (Tenant $tenant): void {
            if (app(FeatureAccessService::class)->state($tenant, 'back_in_stock_alerts') !== ModuleState::Enabled) {
                return;
            }

            $product = Product::query()->find($this->productId);

            if ($product === null) {
                return;
            }

            $variant = $this->variantId === null ? null : ProductVariant::query()->where('product_id', $product->id)->find($this->variantId);
            app(BackInStockService::class)->checkAndNotify($product, $variant);
        });
    }
}
