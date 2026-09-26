<?php

declare(strict_types=1);

namespace App\Modules\Returns\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Checkout\Services\CheckoutService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Payments\Services\OrderPaymentService;
use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Returns\Models\OrderReturnItem;
use App\Modules\Returns\Models\ReturnReason;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Media\StorageQuota;
use App\Shared\Media\UploadRules;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Returns (spec §41): request, approval gate, receipt, resolution by refund
 * (here) or exchange (ExchangeService), restock of resellable lines, close.
 * Money only moves through OrderPaymentService::refund().
 */
final readonly class ReturnService
{
    public const int MAX_PHOTOS = 5;

    public function __construct(
        private InventoryService $inventory,
        private OrderPaymentService $payments,
        private NotificationDispatchService $notifications,
        private TenantSettingsService $settings,
        private PlatformSettingsService $platformSettings,
        private StorageQuota $quota,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items  {order_item_id, quantity, exchange_for_product_id?, exchange_for_product_variant_id?}
     * @param  list<UploadedFile>  $photos
     */
    public function requestReturn(Order $order, ?Customer $customer, array $items, int $reasonId, string $resolutionType, ?string $note = null, array $photos = []): OrderReturn
    {
        validator(['items' => $items, 'resolution_type' => $resolutionType, 'note' => $note, 'photos' => $photos], [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.exchange_for_product_id' => ['sometimes', 'nullable', 'integer'],
            'items.*.exchange_for_product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'resolution_type' => ['required', Rule::in(['refund', 'exchange'])],
            'note' => ['nullable', 'string', 'max:2000'],
            'photos' => ['array', 'max:'.self::MAX_PHOTOS],
            'photos.*' => UploadRules::image(),
        ])->validate();

        $reason = ReturnReason::query()->whereKey($reasonId)->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['reason_id' => ['Choose a return reason.']]);

        if ($reason->requires_photo && $photos === []) {
            throw ValidationException::withMessages(['photos' => ['This reason needs at least one photo.']]);
        }

        foreach ($photos as $photo) {
            $this->quota->assertAllows($photo);
        }

        $return = DB::connection('tenant')->transaction(function () use ($order, $customer, $items, $reason, $resolutionType, $note): OrderReturn {
            /** @var Order $locked */
            $locked = Order::query()->with('items.product')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertEligible($locked);
            $returned = $this->returnedQuantities($locked);
            $lines = $locked->items->keyBy('id');
            $rows = [];

            foreach ($items as $index => $row) {
                /** @var OrderItem|null $line */
                $line = $lines->get((int) $row['order_item_id']);
                $quantity = Quantity::normalize((string) $row['quantity']);

                if ($line === null || $line->product_id === null) {
                    throw ValidationException::withMessages(["items.{$index}.order_item_id" => ['Choose a line of this order.']]);
                }

                $bought = $line->isPhysical() ? (string) $line->quantity_shipped : (string) $line->quantity;
                $left = Quantity::sub($bought, $returned[$line->id] ?? '0');

                if (Quantity::cmp($quantity, $left) > 0) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => ["At most {$left} of this line can be returned."]]);
                }

                [$product, $variant] = $resolutionType === 'exchange' ? $this->replacement($row, $index) : [null, null];

                $rows[] = [
                    'order_item_id' => $line->id,
                    'quantity' => $quantity,
                    'exchange_for_product_id' => $product?->id,
                    'exchange_for_product_variant_id' => $variant?->id,
                ];
            }

            $return = new OrderReturn;
            $return->forceFill([
                'order_id' => $locked->id,
                'customer_id' => $customer?->id ?? $locked->customer_id,
                'return_reason_id' => $reason->id,
                'resolution_type' => $resolutionType,
                'status' => OrderReturn::REQUESTED,
                'customer_note' => $note,
                'requested_at' => now(),
            ])->save();
            $return->forceFill(['return_number' => 'RET-'.str_pad((string) $return->id, 6, '0', STR_PAD_LEFT)])->save();

            foreach ($rows as $row) {
                $item = new OrderReturnItem;
                $item->forceFill(['order_return_id' => $return->id, ...$row])->save();
            }

            return $return;
        });

        foreach ($photos as $photo) {
            $return->addMedia($photo)->usingFileName(Str::uuid().'.'.$photo->guessExtension())->toMediaCollection('photos');
        }

        $variables = $this->variables($return);
        $this->notifications->dispatch('return.requested', $return->order, $variables);
        $this->notifications->dispatch('return.new_request', $return->order, $variables);

        return $return->load('items');
    }

    public function approveReturn(OrderReturn $return, ?bool $requiresPhysicalReturn = null): OrderReturn
    {
        DB::connection('tenant')->transaction(function () use ($return, $requiresPhysicalReturn): void {
            $locked = $this->lock($return, [OrderReturn::REQUESTED], OrderReturn::APPROVED);

            if ($locked->resolution_type === 'exchange') {
                foreach ($locked->items()->with(['exchangeProduct', 'exchangeVariant'])->get() as $item) {
                    $available = $item->exchangeProduct === null ? null : $this->inventory->getAvailableStock($item->exchangeProduct, $item->exchangeVariant);

                    if ($item->exchangeProduct === null || ($available !== null && Quantity::cmp($available, (string) $item->quantity) < 0)) {
                        throw ApiException::unprocessable('exchange_out_of_stock', 'A replacement item is not in stock.');
                    }
                }
            }

            $physical = $requiresPhysicalReturn ?? (bool) $this->settings->get('requires_physical_return_by_default', true);
            $locked->forceFill(['requires_physical_return' => $physical, 'status' => $physical ? OrderReturn::AWAITING : OrderReturn::APPROVED])->save();
            $return->setRawAttributes($locked->getAttributes(), true);
        });

        $this->notifications->dispatch('return.approved', $return->order, [...$this->variables($return),
            'instructions' => $return->requires_physical_return ? 'Please send the items back to us.' : 'No need to send the items back.']);

        return $return;
    }

    public function rejectReturn(OrderReturn $return, string $reason): OrderReturn
    {
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();

        DB::connection('tenant')->transaction(function () use ($return, $reason): void {
            $locked = $this->lock($return, [OrderReturn::REQUESTED], OrderReturn::REJECTED);
            $locked->forceFill(['status' => OrderReturn::REJECTED, 'rejection_reason' => $reason, 'resolved_at' => now()])->save();
            $return->setRawAttributes($locked->getAttributes(), true);
        });

        $this->notifications->dispatch('return.rejected', $return->order, [...$this->variables($return), 'reason' => $reason]);

        return $return;
    }

    /**
     * @param  list<array{item_id: int, condition: string}>  $itemConditions
     */
    public function markReceived(OrderReturn $return, array $itemConditions): OrderReturn
    {
        validator(['items' => $itemConditions], [
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'distinct'],
            'items.*.condition' => ['required', Rule::in(OrderReturnItem::CONDITIONS)],
        ])->validate();

        DB::connection('tenant')->transaction(function () use ($return, $itemConditions): void {
            $locked = $this->lock($return, [OrderReturn::AWAITING], OrderReturn::RECEIVED);
            $items = $locked->items()->get()->keyBy('id');
            $given = collect($itemConditions)->keyBy('item_id');

            if ($items->keys()->diff($given->keys())->isNotEmpty() || $given->keys()->diff($items->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => ['Give the condition of every returned line.']]);
            }

            foreach ($items as $id => $item) {
                $item->forceFill(['condition' => $given[$id]['condition']])->save();
            }

            $locked->forceFill(['status' => OrderReturn::RECEIVED])->save();
            $return->setRawAttributes($locked->getAttributes(), true);
        });

        $this->notifications->dispatch('return.received', $return->order, $this->variables($return));

        return $return;
    }

    /**
     * §41.4: the returned lines' line_total pro rata (after discounts, no
     * shipping), or a lower staff amount. Only from approved or received,
     * under the return lock, so it never refunds twice.
     */
    public function processRefund(OrderReturn $return, ?string $amount = null, ?User $by = null, ?string $idempotencyKey = null): OrderReturn
    {
        $refundAmount = DB::connection('tenant')->transaction(function () use ($return, $amount): string {
            $locked = $this->lock($return, [OrderReturn::APPROVED, OrderReturn::RECEIVED], OrderReturn::REFUNDED);

            if ($locked->resolution_type !== 'refund' || $locked->refund_amount !== null) {
                throw ApiException::unprocessable('return_not_refundable', 'This return is not awaiting a refund.');
            }

            $value = $this->returnedValue($locked);
            $refund = $amount === null ? $value : Money::normalize($amount);

            if (! Money::isPositive($refund) || Money::cmp($refund, $value) > 0) {
                throw ApiException::unprocessable('refund_exceeds_return', 'The refund cannot exceed the value of the returned items.', ['returned_value' => $value]);
            }

            $locked->forceFill(['refund_amount' => $refund])->save();

            return $refund;
        });

        $return->refresh();
        $this->payments->refundOrder($return->order, $refundAmount, 'Return '.$return->return_number, $by, $idempotencyKey ?? 'return-'.$return->id, $return);

        return $return->refresh();
    }

    /**
     * Called when a refund row of the return succeeds: once every refund row
     * of the return has succeeded, it is refunded and restocked.
     */
    public function refundSettled(int $returnId): void
    {
        $settled = DB::connection('tenant')->transaction(function () use ($returnId): bool {
            /** @var OrderReturn|null $locked */
            $locked = OrderReturn::query()->whereKey($returnId)->lockForUpdate()->first();

            if ($locked === null || ! in_array($locked->status, [OrderReturn::APPROVED, OrderReturn::RECEIVED], true)) {
                return false;
            }

            $rows = OrderPayment::query()->where('order_return_id', $locked->id)->where('kind', OrderPayment::REFUND)->get();
            $done = $rows->where('status', OrderPayment::SUCCESSFUL)->reduce(static fn (string $s, OrderPayment $p): string => Money::add($s, Money::sub('0', (string) $p->amount_paid)), Money::normalize(0));

            if ($rows->contains('status', OrderPayment::PENDING) || Money::cmp($done, (string) $locked->refund_amount) < 0) {
                return false;
            }

            $this->restock($locked);
            $locked->forceFill(['status' => OrderReturn::REFUNDED, 'resolved_at' => now()])->save();

            return true;
        });

        if ($settled) {
            $return = OrderReturn::query()->findOrFail($returnId);
            $this->notifications->dispatch('return.refunded', $return->order, [...$this->variables($return),
                'amount' => Money::format((string) $return->refund_amount, $return->order->currency_code)]);
        }
    }

    public function closeReturn(OrderReturn $return): OrderReturn
    {
        DB::connection('tenant')->transaction(function () use ($return): void {
            $locked = $this->lock($return, [OrderReturn::REFUNDED, OrderReturn::EXCHANGED, OrderReturn::REJECTED], OrderReturn::CLOSED);
            $locked->forceFill(['status' => OrderReturn::CLOSED])->save();
            $return->setRawAttributes($locked->getAttributes(), true);
        });

        return $return;
    }

    /**
     * Resellable lines back into their original warehouse (§41.3 step 6);
     * the caller holds the return lock.
     */
    public function restock(OrderReturn $return): void
    {
        if (! $return->requires_physical_return) {
            return;
        }

        foreach ($return->items()->with(['orderItem.product.bundleItems', 'orderItem.variant', 'orderItem.warehouse'])->get() as $item) {
            $line = $item->orderItem;

            if ($item->condition !== 'resellable' || $item->restocked || ! $line->isPhysical() || $line->warehouse === null) {
                continue;
            }

            $this->inventory->applyOrderLines([['warehouse' => $line->warehouse, 'product' => $line->product, 'variant' => $line->variant, 'quantity' => (string) $item->quantity]],
                'restock', $return, 'return_restock');
            $item->forceFill(['restocked' => true])->save();
        }
    }

    /**
     * Σ line_total × returned / bought, rounded per line (§41.4).
     */
    public function returnedValue(OrderReturn $return): string
    {
        $currency = $return->order->currency_code;
        $total = Money::normalize(0);

        foreach ($return->items()->with('orderItem')->get() as $item) {
            $line = $item->orderItem;
            $share = bcdiv(bcmul((string) $line->line_total, (string) $item->quantity, 10), (string) $line->quantity, 10);
            $total = Money::add($total, Money::round($share, $currency));
        }

        return $total;
    }

    /**
     * @return Collection<int, OrderReturn>
     */
    public function getReturnsForOrder(Order $order): Collection
    {
        return OrderReturn::query()->with(['items.orderItem', 'reason'])->where('order_id', $order->id)->orderByDesc('id')->get();
    }

    /**
     * @return Collection<int, OrderReturn>
     */
    public function getReturnsForCustomer(Customer $customer): Collection
    {
        return OrderReturn::query()->with(['items.orderItem', 'reason', 'order:id,order_number'])->where('customer_id', $customer->id)->orderByDesc('id')->get();
    }

    /**
     * @param  array{status?: string, reason_id?: int, resolution_type?: string, from?: string, to?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, OrderReturn>
     */
    public function listReturns(array $filters): LengthAwarePaginator
    {
        return OrderReturn::query()->with(['order:id,order_number', 'reason:id,label'])->withCount('items')
            ->when($filters['status'] ?? null, static fn (Builder $q, $v) => $q->where('status', $v))
            ->when($filters['reason_id'] ?? null, static fn (Builder $q, $v) => $q->where('return_reason_id', $v))
            ->when($filters['resolution_type'] ?? null, static fn (Builder $q, $v) => $q->where('resolution_type', $v))
            ->when($filters['from'] ?? null, static fn (Builder $q, $v) => $q->where('requested_at', '>=', $v))
            ->when($filters['to'] ?? null, static fn (Builder $q, $v) => $q->where('requested_at', '<=', $v))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @return array<string, string>
     */
    public function variables(OrderReturn $return): array
    {
        $order = $return->order;

        return [
            'customer_name' => (string) ($order->customer_name ?? ''),
            'return_number' => (string) $return->return_number,
            'order_number' => $order->order_number,
        ];
    }

    /**
     * @param  list<string>  $from
     */
    public function lock(OrderReturn $return, array $from, string $to): OrderReturn
    {
        /** @var OrderReturn $locked */
        $locked = OrderReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, $from, true)) {
            throw ApiException::invalidTransition($locked->status, $to);
        }

        return $locked;
    }

    private function assertEligible(Order $order): void
    {
        if (! in_array($order->status, [Order::DELIVERED, Order::COMPLETED], true)) {
            throw ApiException::unprocessable('order_not_returnable', 'Only a delivered order can be returned.');
        }

        $days = $this->settings->get('return_window_days') ?? $this->platformSettings->get('default_return_window_days');

        if ($days !== null && $order->completed_at !== null && $order->completed_at->copy()->addDays((int) $days)->isPast()) {
            throw ApiException::unprocessable('return_window_closed', 'The return window for this order has closed.', ['days' => (int) $days]);
        }
    }

    /**
     * Quantity per line in earlier returns that were not rejected.
     *
     * @return array<int, string>
     */
    private function returnedQuantities(Order $order): array
    {
        return OrderReturnItem::query()
            ->join('order_returns', 'order_returns.id', '=', 'order_return_items.order_return_id')
            ->where('order_returns.order_id', $order->id)
            ->where('order_returns.status', '!=', OrderReturn::REJECTED)
            ->groupBy('order_return_items.order_item_id')
            ->selectRaw('order_return_items.order_item_id, SUM(order_return_items.quantity) as q')
            ->pluck('q', 'order_item_id')
            ->map(static fn ($v): string => Quantity::normalize((string) $v))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: Product, 1: ProductVariant|null}
     */
    private function replacement(array $row, int $index): array
    {
        $product = isset($row['exchange_for_product_id']) ? Product::query()->find((int) $row['exchange_for_product_id']) : null;
        $variant = isset($row['exchange_for_product_variant_id']) ? ProductVariant::query()->find((int) $row['exchange_for_product_variant_id']) : null;

        if ($product === null || ! CheckoutService::sellable($product, $variant)) {
            throw ValidationException::withMessages(["items.{$index}.exchange_for_product_id" => ['Choose an available replacement product.']]);
        }

        return [$product, $variant];
    }
}
