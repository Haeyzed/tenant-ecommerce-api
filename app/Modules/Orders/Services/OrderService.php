<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Accounting\Support\AccountingOutbox;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\DigitalDownloadService;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Documents\Services\InvoiceNumberService;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Support\OrderLifecycle;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Plans\Services\PlanLimitService;
use App\Modules\Promotions\Services\FlashSaleService;
use App\Modules\Promotions\Services\PromotionRedemptionService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\FrontendUrl;
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
        private InvoiceNumberService $invoiceNumbers,
        private DigitalDownloadService $downloads,
        private PlatformSettingsService $platformSettings,
        private AccountingOutbox $outbox,
        private OrderLifecycle $lifecycle,
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
     * exchange_rate_used of a new order: given as such (an exchange keeps
     * its original order's), else the inverse of the quote's basket rate
     * (1 base = exchange_rate order currency), else 1 (base currency).
     *
     * @param  array<string, mixed>  $data
     */
    private static function toBaseRate(array $data): string
    {
        if (isset($data['exchange_rate_used'])) {
            return bcadd((string) $data['exchange_rate_used'], '0', CurrencyService::SCALE);
        }

        $rate = (string) ($data['exchange_rate'] ?? '1');

        return bccomp($rate, '1', CurrencyService::SCALE) === 0 ? bcadd('1', '0', CurrencyService::SCALE) : CurrencyService::toBaseRate($rate);
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
            $status = (string) ($data['status'] ?? $this->initialStatus($source));
            $placedAt = $data['placed_at'] ?? now();
            $order->forceFill([
                'order_number' => $this->generateOrderNumber(),
                'customer_id' => $customer?->id,
                'guest_token' => $data['guest_token'] ?? null,
                'customer_name' => $data['customer_name'] ?? $customer?->name,
                'customer_email' => isset($data['customer_email']) ? strtolower((string) $data['customer_email']) : $customer?->email,
                'customer_phone' => $data['customer_phone'] ?? $customer?->phone,
                'status' => $status,
                // A POS sale is handed over at once (§51.3).
                'completed_at' => in_array($status, [Order::DELIVERED, Order::COMPLETED], true) ? $placedAt : null,
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
                'reward_points_redeemed' => (int) ($data['reward_points_redeemed'] ?? 0),
                'reward_points_discount_amount' => $totals['reward_points_discount_amount'] ?? '0',
                'total' => $totals['total'],
                // §48.2: captured once, never recomputed. exchange_rate_used is
                // 1 order currency = x base; a quote's rate is 1 base = x order.
                'exchange_rate_used' => $toBase = self::toBaseRate($data),
                'base_currency_amount' => Money::round(bcmul((string) $totals['total'], $toBase, CurrencyService::SCALE), strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'))),
                'shipping_method_id' => $data['shipping_method_id'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? null,
                'billing_address' => $data['billing_address'] ?? ($data['shipping_address'] ?? null),
                'payment_gateway' => $data['payment_gateway'] ?? null,
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
                'replaces_order_return_id' => $data['replaces_order_return_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'customer_note' => $data['customer_note'] ?? null,
                'pos_session_id' => $data['pos_session_id'] ?? null,
                // An offline POS sale keeps the time it was made (§51.6).
                'placed_at' => $placedAt,
                'payment_expires_at' => $expires ? now()->addMinutes((int) $this->settings->get('unpaid_order_expiry_minutes', 60)) : null,
            ])->save();

            foreach ((array) $data['lines'] as $line) {
                /** @var Product|null $product a non-product line (a gift card, §46.2) has none */
                $product = $line['product'] ?? null;
                /** @var ProductVariant|null $variant */
                $variant = $line['variant'] ?? null;

                $item = new OrderItem;
                $item->forceFill([
                    'order_id' => $order->id,
                    'product_id' => $product?->id,
                    'product_variant_id' => $variant?->id,
                    'name_snapshot' => mb_substr($product === null ? (string) $line['name'] : $product->name.($variant === null ? '' : ' ('.$variant->sku.')'), 0, 255),
                    'sku_snapshot' => $variant?->sku ?? $product?->sku,
                    'seller_id' => $product?->seller_id,
                    'warehouse_id' => ($line['warehouse'] ?? null)?->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'price_source' => $line['price_source'],
                    'unit_cost_snapshot' => $variant?->cost_price ?? $product?->cost_price,
                    'meta' => $line['meta'] ?? null,
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

        // Staff made a POS sale themselves: no new-order alert per till sale.
        if ($source !== 'pos') {
            $this->notifications->dispatch('order.new_order_received', $order, [
                'order_number' => $order->order_number,
                'order_total' => Money::format((string) $order->total, $order->currency_code),
                'customer_name' => (string) ($order->customer_name ?? 'A guest'),
            ]);
        }

        return $order;
    }

    /**
     * The committed sale (§39.4), exactly once: reservations become
     * deductions and promotion redemptions commit. An order with no
     * physical lines is delivered at once. $notify is false for a POS sale:
     * POS sends its own receipt when the store asks for it (§51.3 step 6).
     */
    public function confirmOrder(Order $order, bool $onePass = false, bool $notify = true): void
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
            $locked->forceFill([
                'confirmed_at' => now(),
                'payment_expires_at' => null,
                'invoice_number' => $locked->invoice_number ?? $this->invoiceNumbers->next($locked),
            ]);

            if (! $physical && in_array($locked->status, [Order::PENDING, Order::PROCESSING], true)) {
                $locked->forceFill(['status' => Order::DELIVERED, 'completed_at' => $locked->completed_at ?? now()]);
            }

            $locked->save();
            $this->downloads->grantForOrder($locked);
            $this->outbox->record('postOrderSale', $locked, $locked->confirmed_at ?? now(), 'order_sale:'.$locked->id);
            $this->lifecycle->run(OrderLifecycle::CONFIRMED, $locked);

            if ($locked->completed_at !== null && in_array($locked->status, [Order::DELIVERED, Order::COMPLETED], true)) {
                $this->lifecycle->run(OrderLifecycle::COMPLETED, $locked);
            }

            $order->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($confirmed && $notify) {
            $this->notifications->dispatch('order.confirmed', $order, [
                'customer_name' => (string) ($order->customer_name ?? ''),
                'order_number' => $order->order_number,
                'store_name' => (string) $this->settings->get('store_name', ''),
                'order_total' => Money::format((string) $order->total, $order->currency_code),
                'downloads_note' => $this->downloadsNote($order),
            ]);
        }
    }

    /**
     * §28.4: a confirmed order with digital lines links to the downloads
     * list. The guest token is never put in a message body (notification
     * logs keep bodies); the storefront holds it already.
     */
    private function downloadsNote(Order $order): string
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return '';
        }

        $digital = $order->items()->whereHas('product', static fn (Builder $q): Builder => $q->withTrashed()->where('product_type', Product::DIGITAL))->exists();

        return $digital ? "\n\nYour downloads are ready: ".FrontendUrl::storefront($tenant, '/account/downloads') : '';
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

            if ($status === 'refunded') {
                $this->downloads->revokeForOrder($locked);
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
        $completes = in_array($status, [Order::DELIVERED, Order::COMPLETED], true) && $locked->completed_at === null;

        if ($completes) {
            $changes['completed_at'] = now();
        }

        $locked->forceFill($changes)->save();

        if ($completes) {
            $this->lifecycle->run(OrderLifecycle::COMPLETED, $locked);
        }
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

            if ($byCustomer && ! $this->onlyStoredValuePaid($locked)) {
                throw ApiException::unprocessable('order_paid', 'A paid order can be cancelled by the store only. Contact the store.');
            }

            if ($locked->confirmed_at !== null) {
                $this->moveStock($locked, 'restock', 'order_cancelled');
                $this->redemptions->reverse($locked);
                $this->outbox->record('reverseOrderSale', $locked, now(), 'order_sale_reversal:'.$locked->id);
            } else {
                $this->moveStock($locked, 'release');
                $this->redemptions->release($locked);
            }

            $this->flashSales->release($locked);
            $this->downloads->revokeForOrder($locked);
            // Gift-card redemptions go back onto the cards, redeemed points
            // are restored, an installment plan stops (§46.3, §54.2, §47.4).
            $this->lifecycle->run(OrderLifecycle::CANCELLED, $locked);

            $locked->forceFill([
                'status' => Order::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => mb_substr($reason, 0, 255),
                'payment_expires_at' => null,
            ])->save();

            // Reversals written by the hooks (gift-card credits) move the payment status.
            if (OrderPayment::query()->where('order_id', $locked->id)->exists()) {
                $this->recalculatePaymentStatus($locked);
            }

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
     * A POS void (§51.3): the sale is undone. The caller has already
     * refunded every payment except gift cards (provider calls stay outside
     * this transaction). Here stock returns (pos_void), the sale and its
     * promotions are reversed, and the cancellation hooks credit gift cards
     * back and settle points. The order ends refunded.
     */
    public function voidOrder(Order $order, string $reason): Order
    {
        DB::connection('tenant')->transaction(function () use ($order, $reason): void {
            $locked = $this->lock($order);

            // The refunds before the void may already have marked the sale refunded.
            if ($locked->order_source !== 'pos' || $locked->cancelled_at !== null || ! in_array($locked->status, [Order::COMPLETED, Order::REFUNDED], true)) {
                throw ApiException::invalidTransition($locked->status, Order::REFUNDED);
            }

            $locked->load('items.product.bundleItems', 'items.variant', 'items.warehouse');

            if ($locked->confirmed_at !== null) {
                $this->moveStock($locked, 'restock', 'pos_void');
                $this->redemptions->reverse($locked);
                $this->outbox->record('reverseOrderSale', $locked, now(), 'order_sale_reversal:'.$locked->id);
            }

            $this->flashSales->release($locked);
            $this->downloads->revokeForOrder($locked);
            $this->lifecycle->run(OrderLifecycle::CANCELLED, $locked);

            $locked->forceFill([
                'status' => Order::REFUNDED,
                'cancelled_at' => now(),
                'cancellation_reason' => mb_substr($reason, 0, 255),
            ])->save();

            $this->recalculatePaymentStatus($locked);
            $order->setRawAttributes($locked->getAttributes(), true);
        });

        return $order;
    }

    /**
     * Unpaid, failed, or paid only with a gift card (which cancellation
     * credits back, §46.3): such an order can still expire or be cancelled
     * by its customer.
     */
    private function onlyStoredValuePaid(Order $order): bool
    {
        if (in_array($order->payment_status, ['unpaid', 'failed'], true)) {
            return true;
        }

        return $order->payment_status === 'partially_paid'
            && ! OrderPayment::query()->where('order_id', $order->id)->where('status', OrderPayment::SUCCESSFUL)
                ->where('payment_method', '!=', 'gift_card')->exists();
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
            || ! $this->onlyStoredValuePaid($locked)
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
     * Personal snapshots of an anonymised customer's orders (§26.4 step 3),
     * once each order is settled: finished (delivered, completed, cancelled
     * or refunded), past the return window (none configured: at
     * completion), and with no open return, pending refund or pending
     * chargeback. Orders stay as financial records. Runs at erasure and
     * daily for orders that settle later.
     *
     * @return int the number of orders anonymised
     */
    public function anonymizeSettledOrders(?Customer $customer = null): int
    {
        $days = $this->settings->get('return_window_days') ?? $this->platformSettings->get('default_return_window_days');
        $windowStart = $days === null ? now() : now()->subDays((int) $days);
        $count = 0;

        // At erasure the customer is not yet marked anonymised (erasers run first).
        Order::withTrashed()
            ->when($customer !== null,
                static fn (Builder $q) => $q->where('customer_id', $customer?->id),
                static fn (Builder $q) => $q->whereIn('customer_id', Customer::withTrashed()->select('id')->whereNotNull('anonymized_at')))
            // Not yet anonymised (the address keeps its region, so it is not a marker).
            ->where(static fn (Builder $q) => $q->where('customer_name', '!=', 'Deleted customer')->orWhereNull('customer_name')
                ->orWhereNotNull('customer_email')->orWhereNotNull('customer_phone')->orWhereNotNull('guest_token'))
            ->where(static fn (Builder $q) => $q->whereIn('status', [Order::CANCELLED, Order::REFUNDED])
                ->orWhere(static fn (Builder $done) => $done->whereIn('status', [Order::DELIVERED, Order::COMPLETED])
                    ->where(static fn (Builder $w) => $w->whereNull('completed_at')->orWhere('completed_at', '<=', $windowStart))))
            ->whereNotExists(static fn ($q) => $q->from('order_returns')->whereColumn('order_returns.order_id', 'orders.id')
                ->whereNotIn('status', ['rejected', 'refunded', 'exchanged', 'closed']))
            ->whereNotExists(static fn ($q) => $q->from('order_payments')->whereColumn('order_payments.order_id', 'orders.id')
                ->whereIn('kind', [OrderPayment::REFUND, OrderPayment::CHARGEBACK])->where('status', OrderPayment::PENDING))
            ->chunkById(200, static function ($orders) use (&$count): void {
                foreach ($orders as $order) {
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
                    $count++;
                }
            });

        return $count;
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
