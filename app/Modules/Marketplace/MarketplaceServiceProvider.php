<?php

declare(strict_types=1);

namespace App\Modules\Marketplace;

use App\Modules\CustomFields\Support\CustomFieldEntityRegistry;
use App\Modules\Marketplace\Http\MarketplacePresenter;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Services\SellerLedgerService;
use App\Modules\Marketplace\Services\SellerService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderLifecycle;
use App\Shared\Support\UsageCounterRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the marketplace into the order lifecycle (§50.4: sale entries on
 * confirmation, payable on completion, reversed on cancellation), the
 * max_sellers counter (§11.8) and custom fields for sellers (§23.1). The
 * ledger hooks run whatever the module's state: earned money is owed.
 */
final class MarketplaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(OrderLifecycle::class, function (OrderLifecycle $lifecycle): void {
            $lifecycle->on(OrderLifecycle::CONFIRMED, 'marketplace', fn (Order $order) => $this->app->make(SellerLedgerService::class)->recordOrderLines($order));
            $lifecycle->on(OrderLifecycle::COMPLETED, 'marketplace', fn (Order $order) => $this->app->make(SellerLedgerService::class)->markAvailable($order));
            $lifecycle->on(OrderLifecycle::CANCELLED, 'marketplace', fn (Order $order) => $this->app->make(SellerLedgerService::class)->reverseForOrder($order));
        });

        $this->app->afterResolving(UsageCounterRegistry::class, static function (UsageCounterRegistry $registry): void {
            $registry->register('max_sellers', static fn (): int => SellerService::countApproved());
        });

        $this->app->afterResolving(CustomFieldEntityRegistry::class, static function (CustomFieldEntityRegistry $registry): void {
            $registry->register(MarketplacePresenter::ENTITY, Seller::class, 'sellers', 'marketplace');
        });
    }
}
