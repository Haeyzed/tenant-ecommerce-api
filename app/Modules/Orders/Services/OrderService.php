<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Promotions\Services\FlashSaleService;
use App\Modules\Promotions\Services\PromotionRedemptionService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use App\Shared\Support\UsageCounterRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Orders (spec §39). createOrder() is the only way an order comes to exist;
 * confirmOrder() happens exactly once; payment status is derived from the
 * payments ledger; cancellation undoes stock, promotion usage and flash-sale
 * claims in one transaction. Lock order within a transaction: the order
 * row, then inventory rows, flash-sale rows, promotion rows, coupon rows.
 */
final readonly class OrderService
{
    /** Staff-driven status changes (§39.4); the rest are automatic. */
    private const array MANUAL_TRANSITIONS = [
        Order::PENDING => [Order::PROCESSING],
        Order::SHIPPED => [Order::DELIVERED],
    ];

    public function __construct(
        private InventoryService $inventory,
        private PromotionRedemptionService $redemptions,
        private FlashSaleService $flashSales,
        private NotificationDispatchService $notifications,
        private TenantSettingsService $settings,
        private WarehouseService $warehouses,
        private PlanLimitService $limits,
        private UsageCounterRegistry $counters,
    ) {}

    /**
     * The per-tenant order number: a locked counter row, never reused
     * (§39.3). Runs inside the caller's transaction.
     */
    public function generateOrderNumber(): string
    {
        return DB::connection('tenant')->transaction(static function (): string {
            $row = DB::connection('tenant')->table('sequences')->where('name', 'order_number')->lockForUpdate()->first();

            if ($row === null) {
                DB::connection('tenant')->table('sequences')->insert(['name' => 'order_number', 'next_value' => 100002, 'created_at' => now(), 'updated_at' => now()]);

                return '100001';
            }

            DB::connection('tenant')->table('sequences')->where('name', 'order_number')->update(['next_value' => $row->next_value + 1, 'updated_at' => now()]);

            return (string) $row->next_value;
        });
    }

    /**
     * The only order-creation primitive (§39.5): snapshots every line and
     * total, and reserves stock at the line warehouses in one pass.
     *
     * @param  array<string, mixed>  $data  see CheckoutService::placeOrder() for the shape
     */
    public function createOrder(array $data): Order
    {
        $isTest = (bool) ($data['is_test'] ?? ((string) $this->settings->get('payment_mode', 'test')) === 'test');
        $source = (string) ($data['order_source'] ?? 'online');

        $order = DB::connection('tenant')->transaction(function () use ($data, $isTest, $source): Order {
            if (! $isTest) {
                $this->assertWithinMonthlyLimit();
            }

            $totals = (array) $data['totals'];
            /** @var Customer|null $customer */
            $customer = $data['customer'] ?? null;
            $expires = (bool) ($data['expires'] ?? false) && Money::isPositive((string) $totals['total']);

            $order = new Order;
            $order->forceFill([
                'order_number' => $this->generateOrderNumber(),
                'customer_id' => $customer?->id,
                'guest_token' => $data['guest_token'] ?? null,
                'customer_name' => $data['customer_name'] ?? $customer?->name,
                'customer_email' => isset($data['customer_email']) ? strtolower((string) $data['customer_email']) : $customer?->email,
                'customer_phone' => $data['customer_phone'] ?? $customer?->phone,
                'status' => (string) ($data['status'] ?? $this->initialStatus($source)),
                'payment_status' => 'unpaid',
                'order_type' => (string) ($data['order_type'] ?? 'standard'),
                'order_source' => $source,
                'is_test' => $isTest,
                'currency_code' => (string) $data['currency_code'],
                'prices_include_tax' => (bool) ($data['prices_include_tax'] ?? false),
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'] ?? '0',
                'shipping_amount' => $totals['shipping_amount'] ?? '0',
                'shipping_discount_amount' => $totals['shipping_discount_amount'] ?? '0',
                'shipping_tax_amount' => $totals['shipping_tax_amount'] ?? '0',
                'tax_amount' => $totals['tax_amount'] ?? '0',
                'total' => $totals['total'],
                'base_currency_amount' => $totals['total'],
                'exchange_rate_used' => '1',
                'shipping_method_id' => $data['shipping_method_id'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'billing_address' => $data['billing_address'] ?? ($data['shipping_address'] ?? null),
                'payment_gateway' => $data['payment_gateway'] ?? null,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'customer_note' => $data['customer_note'] ?? null,
                'placed_at' => now(),
                'payment_expires_at' => $expires ? now()->addMinutes((int) $this->settings->get('unpaid_order_expiry_minutes', 60)) : null,
            ])->save();

            foreach ((array) $data['lines'] as $line) {
                /** @var Product $product */
                $product = $line['product'];
                /** @var ProductVariant|null $variant */
                $variant = $line['variant'] ?? null;

                $item = new OrderItem;
                $item->forceFill([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'name_snapshot' => mb_substr($product->name.($variant === null ? '' : ' ('.$variant->sku.')'), 0, 255),
                    'sku_snapshot' => $variant?->sku ?? $product->sku,
                    'seller_id' => $product->seller_id,
                    'warehouse_id' => $line['warehouse']?->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'price_source' => $line['price_source'],
                    'unit_cost_snapshot' => $variant?->cost_price ?? $product->cost_price,
                    'discount_amount' => $line['discount_amount'] ?? '0',
                    'seller_funded_discount_amount' => $line['seller_funded_discount_amount'] ?? '0',
                    'tax_rate_applied' => $line['tax_rate_applied'] ?? '0',
                    'tax_amount' => $line['tax_amount'] ?? '0',
                    'tax_breakdown' => $line['tax_breakdown'] ?? null,
                    'line_total' => $line['line_total'],
                ])->save();
            }

            $order->load('items.product.bundleItems', 'items.variant', 'items.warehouse');

            // One-pass POS sales deduct at confirmation instead (§32.5).
            if (! ($data['one_pass'] ?? false)) {
                $this->moveStock($order, 'reserve');
            }

            return $order;
        });

        $this->notifications->dispatch('order.new_order_received', $order, [
            'order_number' => $order->order_number,
            'order_total' => Money::format((string) $order->total, $order->currency_code),
            'customer_name' => (string) ($order->customer_name ?? 'A guest'),
        ]);

        return $order;
    }

    /**
     * The committed sale (§39.4), exactly once: reservations become
     * deductions and promotion redemptions commit. An order with no
     * physical lines is delivered at once.
     */
    public function confirmOrder(Order $order, bool $onePass = false): void
    {
        $confirmed = DB::connection('tenant')->transaction(function () use ($order, $onePass): bool {
            $locked = $this->lock($order);

            if ($locked->confirmed_at !== null) {
                return false;
            }

            $locked->load('items.product.bundleItems', 'items.variant', 'items.warehouse');
            $this->moveStock($locked, $onePass ? 'deduct_direct' : 'deduct');
            $this->redemptions->commit($locked);

            $physical = $locked->items->contains(static fn (OrderItem $item): bool => $item->isPhysical());
            $locked->forceFill(['confirmed_at' => now(), 'payment_expires_at' => null]);

            if (! $physical && in_array($locked->status, [Order::PENDING, Order::PROCESSING], true)) {
                $locked->forceFill(['status' => Order::DELIVERED, 'completed_at' => $locked->completed_at ?? now()]);
            }

            $locked->save();
            $order->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($confirmed) {
            $this->notifications->dispatch('order.confirmed', $order, [
                'customer_name' => (string) ($order->customer_name ?? ''),
                'order_number' => $order->order_number,
                'store_name' => (string) $this->settings->get('store_name', ''),
                'order_total' => Money::format((string) $order->total, $order->currency_code),
            ]);
        }
    }

    /**
     * payment_status from the ledger (§39.4), after every ledger change,
     * under a lock on the order row. Becoming paid confirms the order.
     */
    public function recalculatePaymentStatus(Order $order): void
    {
        $becamePaid = false;
        $overpaid = null;

        DB::connection('tenant')->transaction(function () use ($order, &$becamePaid, &$overpaid): void {
            $locked = $this->lock($order);
            $rows = OrderPayment::query()->where('order_id', $locked->id)->get(['kind', 'payment_method', 'status', 'amount_paid', 'created_at', 'id']);
            $successful = $rows->where('status', OrderPayment::SUCCESSFUL);
            $netPaid = $successful->reduce(static fn (string $sum, OrderPayment $p): string => Money::add($sum, (string) $p->amount_paid), Money::normalize(0));
            $reversed = $successful->whereIn('kind', [OrderPayment::REFUND, OrderPayment::CHARGEBACK])->isNotEmpty();
            $lastGateway = $rows->where('kind', OrderPayment::PAYMENT)->where('payment_method', 'gateway')->sortBy('id')->last();

            $status = match (true) {
                $reversed && Money::isPositive($netPaid) => 'partially_refunded',
                $reversed => 'refunded',
                Money::cmp($netPaid, (string) $locked->total) >= 0 && ($successful->isNotEmpty() || ! Money::isPositive((string) $locked->total)) => 'paid',
                Money::isPositive($netPaid) => 'partially_paid',
                $lastGateway?->status === OrderPayment::FAILED => 'failed',
                default => 'unpaid',
            };

            $changes = ['payment_status' => $status];

            if ($status === 'paid' && $locked->paid_at === null) {
                $changes['paid_at'] = now();
                $becamePaid = true;
            }

            if ($status === 'refunded' && $locked->status !== Order::CANCELLED) {
                $changes['status'] = Order::REFUNDED;
            }

            if (Money::cmp($netPaid, (string) $locked->total) > 0 && $locked->payment_status !== $status) {
                $overpaid = $netPaid;
            }

            $locked->forceFill($changes)->save();
            $order->setRawAttributes($locked->getAttributes(), true);
        });

        if ($becamePaid) {
            $this->markPaid($order);
        }

        if ($overpaid !== null) {
            $this->notifications->dispatch('order.overpaid', $order, [
                'order_number' => $order->order_number,
                'net_paid' => Money::format($overpaid, $order->currency_code),
                'order_total' => Money::format((string) $order->total, $order->currency_code),
            ]);
        }
    }

    public function markPaid(Order $order): void
    {
        if ($order->confirmed_at === null) {
            $this->confirmOrder($order);
        }
    }

    /**
     * A failed payment never releases stock, sale claims or promotion
     * reservations: the customer can retry until the order expires.
     */
    public function markFailed(Order $order, string $reason = 'payment_failed'): void
    {
        $this->recalculatePaymentStatus($order);

        $this->notifications->dispatch('order.payment_failed', $order, [
            'customer_name' => (string) ($order->customer_name ?? ''),
            'order_number' => $order->order_number,
            'order_url' => '',
        ]);
        $this->notifications->dispatch('order.payment_failed_staff_alert', $order, [
            'order_number' => $order->order_number,
            'order_total' => Money::format((string) $order->total, $order->currency_code),
            'reason' => $reason,
        ]);
    }

    /**
     * Staff status changes: pending → processing and shipped → delivered.
     */
    public function updateOrderStatus(Order $order, string $status): Order
    {
        DB::connection('tenant')->transaction(function () use ($order, $status): void {
            $locked = $this->lock($order);

            if (! in_array($status, self::MANUAL_TRANSITIONS[$locked->status] ?? [], true)) {
                throw ApiException::invalidTransition($locked->status, $status);
            }

            $this->setStatus($locked, $status);
            $order->setRawAttributes($locked->getAttributes(), true);
        });

        $this->notifyStatus($order, $status);

        return $order;
    }

    /**
     * Sets a status and the completion timestamp; used by staff changes and
     * by shipments. The caller holds the order lock.
     */
    public function setStatus(Order $locked, string $status): void
    {
        $changes = ['status' => $status];

        if (in_array($status, [Order::DELIVERED, Order::COMPLETED], true) && $locked->completed_at === null) {
            $changes['completed_at'] = now();
        }

        $locked->forceFill($changes)->save();
    }

    public function notifyStatus(Order $order, string $status): void
    {
        $variables = ['customer_name' => (string) ($order->customer_name ?? ''), 'order_number' => $order->order_number];

        if ($status === Order::DELIVERED) {
            $this->notifications->dispatch('order.delivered', $order, [...$variables, 'store_name' => (string) $this->settings->get('store_name', '')]);
        } elseif ($status !== Order::SHIPPED) {
            $this->notifications->dispatch('order.status_changed', $order, [...$variables, 'status' => str_replace('_', ' ', $status)]);
        }
    }

    /**
     * §39.5: only while pending or processing and before anything ships.
     * A customer may cancel only an unpaid (or failed) order (A-23); staff
     * may cancel paid orders, then refund explicitly.
     */
    public function cancelOrder(Order $order, string $reason, bool $byCustomer = false): Order
    {
        DB::connection('tenant')->transaction(function () use ($order, $reason, $byCustomer): void {
            $locked = $this->lock($order);

            if (! in_array($locked->status, [Order::PENDING, Order::PROCESSING], true)) {
                throw ApiException::invalidTransition($locked->status, Order::CANCELLED);
            }

            $locked->load('items.product.bundleItems', 'items.variant', 'items.warehouse');

            if ($locked->items->contains(static fn (OrderItem $item): bool => Money::isPositive((string) $item->quantity_shipped))) {
                throw ApiException::unprocessable('order_shipped', 'Part of this order has shipped. Use a return or a refund instead.');
            }

            if ($byCustomer && ! in_array($locked->payment_status, ['unpaid', 'failed'], true)) {
                throw ApiException::unprocessable('order_paid', 'A paid order can be cancelled by the store only. Contact the store.');
            }

            if ($locked->confirmed_at !== null) {
                $this->moveStock($locked, 'restock', 'order_cancelled');
                $this->redemptions->reverse($locked);
            } else {
                $this->moveStock($locked, 'release');
                $this->redemptions->release($locked);
            }

            $this->flashSales->release($locked);

            $locked->forceFill([
                'status' => Order::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => mb_substr($reason, 0, 255),
                'payment_expires_at' => null,
            ])->save();

            $order->setRawAttributes($locked->getAttributes(), true);
        });

        $this->notifications->dispatch('order.cancelled', $order, [
            'customer_name' => (string) ($order->customer_name ?? ''),
            'order_number' => $order->order_number,
            'reason' => $reason === 'payment_timeout' ? 'the payment was not completed in time' : $reason,
        ]);

        return $order;
    }

    /**
     * The ExpireUnpaidOrder job (§39.5). Returns "cancelled", "pending"
     * (a gateway payment is still in flight: the caller verifies and
     * retries) or "skipped".
     */
    public function expireUnpaidOrder(Order $order): string
    {
        $locked = Order::query()->find($order->id);

        if ($locked === null || $locked->confirmed_at !== null || $locked->status === Order::CANCELLED
            || ! in_array($locked->payment_status, ['unpaid', 'failed'], true)
            || $locked->payment_expires_at === null || $locked->payment_expires_at->isFuture()) {
            return 'skipped';
        }

        if (OrderPayment::query()->where('order_id', $locked->id)->where('kind', OrderPayment::PAYMENT)->where('status', OrderPayment::PENDING)->exists()) {
            return 'pending';
        }

        $this->cancelOrder($locked, 'payment_timeout');

        return 'cancelled';
    }

    /**
     * Moves an unshipped line to another warehouse (§32.4 item 4): the
     * reservation moves before confirmation; the deduction after it.
     */
    public function changeLineWarehouse(OrderItem $item, Warehouse $warehouse): OrderItem
    {
        DB::connection('tenant')->transaction(function () use ($item, $warehouse): void {
            $order = $this->lock($item->order);
            /** @var OrderItem $locked */
            $locked = OrderItem::query()->with(['product.bundleItems', 'variant', 'warehouse'])->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($order->status === Order::CANCELLED || Money::isPositive((string) $locked->quantity_shipped)) {
                throw ApiException::unprocessable('line_not_movable', 'Only an unshipped line of an open order can change warehouse.');
            }

            if (! $locked->isPhysical() || $locked->warehouse === null) {
                throw ApiException::unprocessable('line_not_stocked', 'This line holds no stock.');
            }

            if (! $warehouse->is_active) {
                throw ApiException::unprocessable('warehouse_inactive', 'The warehouse is inactive.');
            }

            if ($locked->warehouse_id === $warehouse->id) {
                return;
            }

            $from = [['warehouse' => $locked->warehouse, 'product' => $locked->product, 'variant' => $locked->variant, 'quantity' => (string) $locked->quantity]];
            $to = [['warehouse' => $warehouse, 'product' => $locked->product, 'variant' => $locked->variant, 'quantity' => (string) $locked->quantity]];

            try {
                if ($order->confirmed_at === null) {
                    $this->inventory->applyOrderLines($from, 'release', $order, 'warehouse_change');
                    $this->inventory->applyOrderLines($to, 'reserve', $order, 'warehouse_change');
                } else {
                    $this->inventory->applyOrderLines($from, 'restock', $order, 'warehouse_change');
                    $this->inventory->applyOrderLines($to, 'deduct_direct', $order, 'warehouse_change');
                }
            } catch (InsufficientStockException $e) {
                throw ApiException::conflict('stock_conflict', 'The warehouse does not have enough stock for this line.', $e->details);
            }

            $locked->forceFill(['warehouse_id' => $warehouse->id])->save();
            $item->setRawAttributes($locked->getAttributes(), true);
        });

        return $item->refresh();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function listOrders(array $filters, ?User $viewer = null): LengthAwarePaginator
    {
        $visible = $this->warehouses->visibleIds($viewer);
        $search = trim((string) ($filters['search'] ?? ''));

        return Order::query()
            ->withCount('items')
            ->when($visible !== null, static fn (Builder $q) => $q->whereHas('items', static fn (Builder $i) => $i->whereIn('warehouse_id', $visible)))
            ->when($search !== '', static fn (Builder $q) => $q->where(static fn (Builder $w) => $w->where('order_number', $search)->orWhere('customer_email', strtolower($search))
                ->orWhere('customer_name', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->when($filters['status'] ?? null, static fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['payment_status'] ?? null, static fn (Builder $q, $v) => $q->where('payment_status', $v))
            ->when($filters['order_source'] ?? null, static fn (Builder $q, $v) => $q->where('order_source', $v))
            ->when($filters['order_type'] ?? null, static fn (Builder $q, $v) => $q->where('order_type', $v))
            ->when($filters['customer_id'] ?? null, static fn (Builder $q, $v) => $q->where('customer_id', $v))
            ->when($filters['warehouse_id'] ?? null, static fn (Builder $q, $v) => $q->whereHas('items', static fn (Builder $i) => $i->where('warehouse_id', $v)))
            ->when($filters['promotion_id'] ?? null, static fn (Builder $q, $v) => $q->whereHas('redemptions', static fn (Builder $r) => $r->where('promotion_id', $v)))
            ->when(array_key_exists('is_test', $filters), static fn (Builder $q) => $q->where('is_test', (bool) $filters['is_test']))
            ->when($filters['from'] ?? null, static fn (Builder $q, $v) => $q->where('placed_at', '>=', $v))
            ->when($filters['to'] ?? null, static fn (Builder $q, $v) => $q->where('placed_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function listOrdersForCustomer(Customer $customer, int $perPage = 20): LengthAwarePaginator
    {
        return Order::query()->withCount('items')->where('customer_id', $customer->id)->orderByDesc('id')->paginate($perPage);
    }

    public function getOrder(Order $order): Order
    {
        return $order->load(['items.product:id,name,slug,product_type', 'items.variant:id,sku', 'items.warehouse:id,name', 'redemptions', 'shippingMethod:id,name']);
    }

    /**
     * Whether the buyer has any other order that was not cancelled.
     */
    public function hasOrdered(?int $customerId, ?string $email, ?int $excludeOrderId = null): bool
    {
        if ($customerId === null && $email === null) {
            return false;
        }

        return Order::query()
            ->where('status', '!=', Order::CANCELLED)
            ->when($excludeOrderId !== null, static fn (Builder $q) => $q->whereKeyNot($excludeOrderId))
            ->where(static fn (Builder $q) => $q
                ->when($customerId !== null, static fn (Builder $c) => $c->orWhere('customer_id', $customerId))
                ->when($email !== null, static fn (Builder $c) => $c->orWhere('customer_email', $email)))
            ->exists();
    }

    /**
     * A guest who registers or signs in with the guest token takes over the
     * guest orders placed with their email (§39.6, A-24).
     */
    public function linkGuestOrders(Customer $customer, string $guestToken): int
    {
        if ($customer->email === null) {
            return 0;
        }

        return Order::query()->whereNull('customer_id')->where('guest_token', $guestToken)->where('customer_email', strtolower($customer->email))
            ->update(['customer_id' => $customer->id]);
    }

    /**
     * Orders placed this calendar month that count against the plan
     * (§11.8): test orders never do.
     */
    public static function countThisMonth(): int
    {
        return Order::withTrashed()->where('is_test', false)->where('placed_at', '>=', now()->startOfMonth())->count();
    }

    private function assertWithinMonthlyLimit(): void
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return;
        }

        $limit = $this->limits->getLimit($tenant, 'max_orders_per_month');

        if ($limit !== null && $this->counters->count('max_orders_per_month') >= $limit) {
            throw ApiException::forbidden('limit_reached', 'The store has reached its monthly order limit.', ['limit' => 'max_orders_per_month', 'limit_value' => $limit]);
        }
    }

    private function initialStatus(string $source): string
    {
        return match ($source) {
            'online' => (string) ($this->settings->get('default_order_status') ?: Order::PENDING),
            'pos' => Order::COMPLETED,
            default => Order::PENDING,
        };
    }

    /**
     * One stock operation over the order's physical lines; a shortfall is
     * 409 stock_conflict.
     */
    private function moveStock(Order $order, string $operation, ?string $reason = null): void
    {
        $lines = [];

        foreach ($order->items as $item) {
            if (! $item->isPhysical()) {
                continue;
            }

            if ($item->warehouse === null) {
                throw ApiException::conflict('stock_conflict', "{$item->name_snapshot} has no fulfilling warehouse.");
            }

            $lines[] = ['warehouse' => $item->warehouse, 'product' => $item->product, 'variant' => $item->variant, 'quantity' => (string) $item->quantity];
        }

        if ($lines === []) {
            return;
        }

        try {
            $this->inventory->applyOrderLines($lines, $operation, $order, $reason);
        } catch (InsufficientStockException $e) {
            throw ApiException::conflict('stock_conflict', 'An item on this order is no longer in stock.', $e->details);
        }
    }

    private function lock(Order $order): Order
    {
        /** @var Order */
        return Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }
}
