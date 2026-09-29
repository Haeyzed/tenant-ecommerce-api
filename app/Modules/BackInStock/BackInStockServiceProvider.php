<?php

declare(strict_types=1);

namespace App\Modules\BackInStock;

use App\Modules\BackInStock\Jobs\NotifyBackInStockSubscribers;
use App\Modules\BackInStock\Models\BackInStockSubscription;
use App\Modules\BackInStock\Services\BackInStockService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\Inventory\Events\StockReplenished;
use App\Modules\Plans\Enums\ModuleState;
use App\Modules\Plans\Services\FeatureAccessService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Back-in-stock alerts (§56) listen to StockReplenished (§32.7), raised
 * after the stock change commits; the alerts are sent by a queued job.
 * Personal data (§26.4): a deleted customer's requests are removed.
 */
final class BackInStockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(CustomerPrivacyRegistry::class, function (CustomerPrivacyRegistry $privacy): void {
            $privacy->registerEraser('back_in_stock', fn (Customer $customer) => $this->app->make(BackInStockService::class)->eraseForCustomer($customer));
            $privacy->registerSection('back_in_stock', static fn (Customer $customer): iterable => BackInStockSubscription::query()
                ->with(['product:id,name', 'variant:id,sku'])->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(static fn (BackInStockSubscription $r): array => [
                    'product' => $r->product->name,
                    'variant' => $r->variant?->sku,
                    'email' => $r->email,
                    'notified_at' => $r->notified_at?->toIso8601String(),
                    'created_at' => $r->created_at->toIso8601String(),
                ]));
        });
    }

    public function boot(): void
    {
        Event::listen(StockReplenished::class, function (StockReplenished $event): void {
            $tenant = tenant();

            // No job while the capability is off: nothing would be sent.
            if (! $tenant instanceof Tenant || $this->app->make(FeatureAccessService::class)->state($tenant, 'back_in_stock_alerts') !== ModuleState::Enabled) {
                return;
            }

            if (BackInStockSubscription::query()->whereNull('notified_at')->exists()) {
                NotifyBackInStockSubscribers::dispatch($event->tenantId, $event->productId, $event->variantId);
            }
        });
    }
}
