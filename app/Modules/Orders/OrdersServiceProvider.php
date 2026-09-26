<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Modules\Customers\Events\CustomerAuthenticated;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPrivacyRegistry;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\WarehouseUsage;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Promotions\Support\BuyerHistory;
use App\Shared\Support\UsageCounterRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires orders into their extension points: first-order history for
 * promotions (§37.2), the monthly order counter (§11.8), guest order
 * linking on login (§39.6), customer privacy (§26.4) and the records that
 * block a warehouse's deletion (§32.9).
 */
final class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(BuyerHistory::class, function (BuyerHistory $history): void {
            $history->useResolver(fn (?int $customerId, ?string $email, ?int $excludeOrderId): bool => $this->app->make(OrderService::class)->hasOrdered($customerId, $email, $excludeOrderId));
        });

        $this->app->afterResolving(UsageCounterRegistry::class, static function (UsageCounterRegistry $registry): void {
            $registry->register('max_orders_per_month', static fn (): int => OrderService::countThisMonth());
        });

        $this->app->afterResolving(WarehouseUsage::class, static function (WarehouseUsage $usage): void {
            $usage->register('orders', static fn (Warehouse $w): bool => OrderItem::query()->where('warehouse_id', $w->id)->exists());
        });

        // Orders are financial records: they stay, without personal data.
        $this->app->afterResolving(CustomerPrivacyRegistry::class, static function (CustomerPrivacyRegistry $privacy): void {
            $privacy->registerEraser('orders', static function (Customer $customer): void {
                Order::withTrashed()->where('customer_id', $customer->id)->get()->each(static function (Order $order): void {
                    $keep = static fn (?array $address): ?array => $address === null ? null
                        : ['country_id' => $address['country_id'] ?? null, 'state_id' => $address['state_id'] ?? null];

                    $order->forceFill([
                        'customer_name' => 'Deleted customer',
                        'customer_email' => null,
                        'customer_phone' => null,
                        'guest_token' => null,
                        'shipping_address' => $keep($order->shipping_address),
                        'billing_address' => $keep($order->billing_address),
                        'customer_note' => null,
                    ])->saveQuietly();
                });
            });

            $privacy->registerSection('orders', static fn (Customer $customer): iterable => Order::query()->with('items')
                ->where('customer_id', $customer->id)->orderBy('id')->get()
                ->map(static fn (Order $order): array => [
                    'order_number' => $order->order_number,
                    'placed_at' => $order->placed_at->toIso8601String(),
                    'status' => $order->status,
                    'total' => (string) $order->total.' '.$order->currency_code,
                    'shipping_address' => $order->shipping_address,
                    'items' => $order->items->map(static fn (OrderItem $i): string => (string) $i->quantity.' × '.$i->name_snapshot)->all(),
                ]));
        });
    }

    public function boot(): void
    {
        Event::listen(CustomerAuthenticated::class, function (CustomerAuthenticated $event): void {
            if ($event->guestToken !== null) {
                $this->app->make(OrderService::class)->linkGuestOrders($event->customer, $event->guestToken);
            }
        });
    }
}
