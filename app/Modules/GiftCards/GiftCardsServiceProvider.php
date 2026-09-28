<?php

declare(strict_types=1);

namespace App\Modules\GiftCards;

use App\Modules\GiftCards\Services\GiftCardService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderLifecycle;
use Illuminate\Support\ServiceProvider;

/**
 * Gift cards on the order lifecycle (§46.2, §46.3): a paid purchase issues
 * its card; a cancelled order credits its redemptions back. Both run
 * whatever the module's state: the customer already paid.
 */
final class GiftCardsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(OrderLifecycle::class, function (OrderLifecycle $lifecycle): void {
            $lifecycle->on(OrderLifecycle::CONFIRMED, 'gift_cards', fn (Order $order) => $this->app->make(GiftCardService::class)->issueForOrder($order));
            $lifecycle->on(OrderLifecycle::CANCELLED, 'gift_cards', fn (Order $order) => $this->app->make(GiftCardService::class)->reverseForCancelledOrder($order));
        });
    }
}
