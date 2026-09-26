<?php

declare(strict_types=1);

namespace App\Modules\Cart;

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Cart\Services\CartService;
use App\Modules\Customers\Events\CustomerAuthenticated;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Merges the guest cart on login and registration (§38.2) and registers
 * the cart with customer privacy (§26.4).
 */
final class CartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(CustomerPrivacyRegistry::class, static function (CustomerPrivacyRegistry $privacy): void {
            $privacy->registerEraser('cart', static fn (Customer $customer) => Cart::query()->where('customer_id', $customer->id)->delete());
            $privacy->registerSection('cart', static fn (Customer $customer): iterable => CartItem::query()
                ->whereHas('cart', static fn ($q) => $q->where('customer_id', $customer->id))
                ->with(['product:id,name', 'variant:id,sku'])
                ->get()
                ->map(static fn (CartItem $item): array => [
                    'product' => $item->product?->name,
                    'variant' => $item->variant?->sku,
                    'quantity' => (string) $item->quantity,
                    'added_at' => $item->created_at?->toIso8601String(),
                ]));
        });
    }

    public function boot(): void
    {
        Event::listen(CustomerAuthenticated::class, function (CustomerAuthenticated $event): void {
            if ($event->guestToken !== null) {
                $this->app->make(CartService::class)->mergeGuestCartIntoCustomer($event->guestToken, $event->customer);
            }
        });
    }
}
