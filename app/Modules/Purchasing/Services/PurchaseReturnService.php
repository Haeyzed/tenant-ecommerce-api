<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Support\AccountingOutbox;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Support\StockChange;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseReturnItem;
use App\Modules\Purchasing\Models\PurchaseReturnReason;
use App\Modules\Purchasing\Support\PurchaseLines;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use App\Shared\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Received stock going back to its supplier (spec §49.5). Approval takes
 * the stock out immediately and posts Dr Accounts Payable, Cr Inventory;
 * resolution records a refund (a negative supplier payment) or keeps the
 * credit against future balances.
 */
final readonly class PurchaseReturnService
{
    /** Returns that still hold quantity against a line. */
    private const array ACTIVE = [PurchaseReturn::REQUESTED, PurchaseReturn::APPROVED, PurchaseReturn::SHIPPED_BACK, PurchaseReturn::REFUNDED, PurchaseReturn::CLOSED];

    public function __construct(
        private InventoryService $inventory,
        private AccountingOutbox $outbox,
        private SupplierPaymentService $payments,
        private CurrencyService $currencies,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items  purchase_order_item_id, quantity
     */
    public function requestReturn(PurchaseOrder $order, Warehouse $warehouse, array $items, int $reasonId, ?string $note, User $by): PurchaseReturn
    {
        Validator::make(['items' => $items, 'reason_id' => $reasonId, 'note' => $note], [
            'items' => ['required', 'array', 'min:1', 'max:'.PurchaseLines::MAX_LINES],
            'items.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'reason_id' => [Rule::exists('tenant.purchase_return_reasons', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['reason_id.exists' => 'Choose an active purchase return reason.'])->validate();

        if (! in_array($order->status, [PurchaseOrder::PARTIALLY_RECEIVED, PurchaseOrder::RECEIVED], true)) {
            throw ApiException::unprocessable('purchase_order_not_received', 'Only received stock can be returned.');
        }

        return DB::connection('tenant')->transaction(function () use ($order, $warehouse, $items, $reasonId, $note, $by): PurchaseReturn {
            PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->first();
            $lines = $order->items()->get()->keyBy('id');
            $held = $this->returnedPerLine($order);
            $errors = [];
            $rows = [];

            foreach ($items as $i => $row) {
                /** @var PurchaseOrderItem|null $line */
                $line = $lines->get((int) $row['purchase_order_item_id']);
                $quantity = Quantity::normalize((string) $row['quantity']);

                if ($line === null) {
                    $errors["items.{$i}.purchase_order_item_id"][] = 'This line is not on the purchase order.';

                    continue;
                }

                $returnable = Quantity::sub((string) $line->quantity_received, $held[$line->id] ?? Quantity::normalize(0));

                if (Quantity::cmp($quantity, $returnable) > 0) {
                    $errors["items.{$i}.quantity"][] = 'At most '.$returnable.' of this line can still be returned.';

                    continue;
                }

                $rows[] = ['purchase_order_item_id' => $line->id, 'quantity' => $quantity, 'unit_cost' => (string) $line->unit_cost];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $return = new PurchaseReturn(['note' => $note]);
            $return->forceFill([
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'warehouse_id' => $warehouse->id,
                'purchase_return_reason_id' => $reasonId,
                'status' => PurchaseReturn::REQUESTED,
                'requested_at' => now(),
                'created_by' => $by->id,
            ])->save();

            foreach ($rows as $row) {
                $return->items()->create($row);
            }

            return $return;
        });
    }

    /**
     * Stock leaves the warehouse now (no inspection step) and payables fall.
     */
    public function approveReturn(PurchaseReturn $return): void
    {
        DB::connection('tenant')->transaction(function () use ($return): void {
            $locked = $this->lock($return, [PurchaseReturn::REQUESTED]);
            $locked->load(['items.orderItem.product', 'items.orderItem.variant', 'warehouse', 'purchaseOrder']);
            $order = $locked->purchaseOrder;
            $rate = $order->toBaseRate();
            $base = $this->currencies->baseCurrency();

            $this->inventory->apply($locked->items->map(static fn (PurchaseReturnItem $item): StockChange => new StockChange(
                $locked->warehouse, $item->orderItem->product, $item->orderItem->variant, Quantity::neg((string) $item->quantity), Quantity::normalize(0),
                'purchase_return', null, Money::round(Money::mul((string) $item->unit_cost, $rate), $base),
            ))->all(), $locked);

            $locked->forceFill(['status' => PurchaseReturn::APPROVED])->save();

            $this->outbox->record('postPurchaseReturn', $locked, now(), 'purchase_return:'.$locked->id, [
                'value' => $locked->value($order->currency_code),
                'rate' => $rate,
            ]);

            $return->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function rejectReturn(PurchaseReturn $return, string $reason): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();

        DB::connection('tenant')->transaction(function () use ($return, $reason): void {
            $locked = $this->lock($return, [PurchaseReturn::REQUESTED]);
            $locked->forceFill(['status' => PurchaseReturn::REJECTED, 'rejection_reason' => $reason, 'resolved_at' => now()])->save();
            $return->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function markShippedBack(PurchaseReturn $return): void
    {
        DB::connection('tenant')->transaction(function () use ($return): void {
            $locked = $this->lock($return, [PurchaseReturn::APPROVED]);
            $locked->forceFill(['status' => PurchaseReturn::SHIPPED_BACK])->save();
            $return->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * refund: a negative supplier payment (money back), defaulting to the
     * returned value; credit_note: nothing more, the credit reduces the
     * order balance (§49.5).
     *
     * @param  array<string, mixed>  $paymentData  amount?, payment_method, account_id?, paid_at?, reference?, notes?
     */
    public function processSupplierRefund(PurchaseReturn $return, string $resolution, User $by, array $paymentData = []): void
    {
        Validator::make(['resolution' => $resolution], ['resolution' => ['required', Rule::in(PurchaseReturn::RESOLUTIONS)]])->validate();

        DB::connection('tenant')->transaction(function () use ($return, $resolution, $by, $paymentData): void {
            $locked = $this->lock($return, [PurchaseReturn::APPROVED, PurchaseReturn::SHIPPED_BACK]);
            $locked->load(['purchaseOrder', 'supplier']);

            if ($resolution === 'refund') {
                $order = $locked->purchaseOrder;
                $amount = (string) ($paymentData['amount'] ?? $locked->value($order->currency_code));
                $data = array_merge(['payment_method' => 'bank_transfer'], $paymentData);
                unset($data['amount']);
                $data['amount_paid'] = Money::sub('0', Money::normalize(ltrim($amount, '-')));

                $this->payments->recordPayment($locked->supplier, $data, $by, $order, $locked);
            }

            $locked->forceFill(['status' => PurchaseReturn::REFUNDED, 'resolution' => $resolution, 'resolved_at' => now()])->save();
            $return->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function closeReturn(PurchaseReturn $return): void
    {
        DB::connection('tenant')->transaction(function () use ($return): void {
            $locked = $this->lock($return, [PurchaseReturn::REFUNDED]);
            $locked->forceFill(['status' => PurchaseReturn::CLOSED])->save();
            $return->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @return Collection<int, PurchaseReturn>
     */
    public function getReturnsForPurchaseOrder(PurchaseOrder $order): Collection
    {
        return PurchaseReturn::query()->with(['items', 'reason:id,label'])->where('purchase_order_id', $order->id)->orderBy('id')->get();
    }

    public function getReturn(PurchaseReturn $return): PurchaseReturn
    {
        return $return->load(['purchaseOrder:id,po_number,currency_code', 'supplier:id,name', 'warehouse:id,name', 'reason:id,label',
            'items.orderItem.product:id,name,sku', 'items.orderItem.variant:id,sku']);
    }

    /**
     * @param  array{status?: string, supplier_id?: int, purchase_order_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, PurchaseReturn>
     */
    public function listReturns(array $filters = []): LengthAwarePaginator
    {
        return PurchaseReturn::query()->with(['purchaseOrder:id,po_number,currency_code', 'supplier:id,name', 'reason:id,label', 'items'])
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['supplier_id']), static fn ($q) => $q->where('supplier_id', $filters['supplier_id']))
            ->when(isset($filters['purchase_order_id']), static fn ($q) => $q->where('purchase_order_id', $filters['purchase_order_id']))
            ->orderByDesc('requested_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    // ---- Reasons -----------------------------------------------------------

    /**
     * @return Collection<int, PurchaseReturnReason>
     */
    public function listReasons(): Collection
    {
        return PurchaseReturnReason::query()->orderBy('label')->get();
    }

    /**
     * @param  array<string, mixed>  $data  label, is_active?
     */
    public function saveReason(array $data, ?PurchaseReturnReason $reason = null): PurchaseReturnReason
    {
        $validated = Validator::make($data, [
            'label' => [$reason === null ? 'required' : 'sometimes', 'string', 'max:120', Rule::unique('tenant.purchase_return_reasons', 'label')->ignore($reason?->id)],
            'is_active' => ['sometimes', 'boolean'],
        ])->validate();

        $reason ??= new PurchaseReturnReason;
        $reason->fill($validated);

        if (array_key_exists('is_active', $validated)) {
            $reason->is_active = (bool) $validated['is_active'];
        }

        $reason->save();

        return $reason;
    }

    /**
     * Quantity per purchase-order line already held by a return that was
     * not rejected.
     *
     * @return array<int, string>
     */
    private function returnedPerLine(PurchaseOrder $order): array
    {
        return DB::connection('tenant')->table('purchase_return_items as ri')->join('purchase_returns as r', 'r.id', '=', 'ri.purchase_return_id')
            ->where('r.purchase_order_id', $order->id)->whereIn('r.status', self::ACTIVE)
            ->groupBy('ri.purchase_order_item_id')->selectRaw('ri.purchase_order_item_id, SUM(ri.quantity) as quantity')
            ->pluck('quantity', 'ri.purchase_order_item_id')
            ->map(static fn ($q): string => Quantity::normalize((string) $q))->all();
    }

    /**
     * @param  list<string>  $statuses
     */
    private function lock(PurchaseReturn $return, array $statuses): PurchaseReturn
    {
        /** @var PurchaseReturn $locked */
        $locked = PurchaseReturn::query()->lockForUpdate()->findOrFail($return->id);

        if (! in_array($locked->status, $statuses, true)) {
            throw ApiException::invalidTransition($locked->status, $statuses[0]);
        }

        return $locked;
    }
}
