<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\AccountingOutbox;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The supplier payments ledger (spec §49.4): money paid outside the
 * platform, with the accounting request in the same transaction. A payment
 * against an order is in the order's currency at the order's captured rate
 * (no FX gain or loss, as for sales, A-40); a bulk payment is in the base
 * currency. Refund rows (negative, from a purchase return) belong to the
 * return and cannot be edited here.
 */
final readonly class SupplierPaymentService
{
    /** Orders that owe money: submitted or further, not cancelled. */
    private const array OWING = [PurchaseOrder::SUBMITTED, PurchaseOrder::PARTIALLY_RECEIVED, PurchaseOrder::RECEIVED];

    public function __construct(
        private AccountingOutbox $outbox,
        private CurrencyService $currencies,
    ) {}

    /**
     * @param  array<string, mixed>  $data  amount_paid, payment_method, account_id?, paid_at?, reference?, notes?, amount_due?, amount_received?, change_given?
     */
    public function recordPayment(Supplier $supplier, array $data, User $by, ?PurchaseOrder $order = null, ?PurchaseReturn $refundFor = null): SupplierPayment
    {
        $validated = $this->validate($data, true, $refundFor !== null);

        if ($order !== null) {
            if ($order->supplier_id !== $supplier->id) {
                throw ApiException::unprocessable('validation_failed', 'That purchase order belongs to another supplier.');
            }

            if (! in_array($order->status, self::OWING, true)) {
                throw ApiException::unprocessable('purchase_order_not_payable', 'Only submitted purchase orders can be paid.');
            }
        }

        return DB::connection('tenant')->transaction(function () use ($supplier, $validated, $by, $order, $refundFor): SupplierPayment {
            $currency = $order?->currency_code ?? $this->currencies->baseCurrency();

            $payment = new SupplierPayment($validated);
            $payment->forceFill([
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $order?->id,
                'purchase_return_id' => $refundFor?->id,
                'amount_paid' => Money::round(Money::normalize((string) $validated['amount_paid']), $currency),
                'currency_code' => $currency,
                'exchange_rate_used' => $order?->toBaseRate() ?? '1',
                'paid_at' => $validated['paid_at'] ?? now(),
                'amount_due' => $validated['amount_due'] ?? ($order === null ? null : $this->getBalanceForPurchaseOrder($order)),
                'recorded_by_user_id' => $by->id,
            ])->save();

            $this->post($payment);

            return $payment;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePayment(SupplierPayment $payment, array $data): SupplierPayment
    {
        $this->assertManual($payment);
        $validated = $this->validate($data, false, false);

        DB::connection('tenant')->transaction(function () use ($payment, $validated): void {
            /** @var SupplierPayment $locked */
            $locked = SupplierPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $before = [(string) $locked->amount_paid, $locked->payment_method, $locked->account_id, $locked->paid_at->toIso8601String()];

            $locked->fill($validated);

            if (isset($validated['amount_paid'])) {
                $locked->amount_paid = Money::round(Money::normalize((string) $validated['amount_paid']), $locked->currency_code);
            }

            $locked->save();

            // Only a change that moves money re-posts (§57.3 update rule).
            if ($before !== [(string) $locked->amount_paid, $locked->payment_method, $locked->account_id, $locked->paid_at->toIso8601String()]) {
                $this->repost($locked, 'Payment edited');
            }

            $payment->setRawAttributes($locked->getAttributes(), true);
        });

        return $payment;
    }

    public function deletePayment(SupplierPayment $payment): void
    {
        $this->assertManual($payment);

        DB::connection('tenant')->transaction(function () use ($payment): void {
            $base = 'supplier_payment:'.$payment->id;
            $current = $this->outbox->currentKey($base);

            if ($current !== null) {
                $this->outbox->record('reversePosting', $payment, now(), 'supplier_payment_reversal:'.$payment->id.':deleted', ['posting_key' => $current, 'reason' => 'Payment deleted']);
            }

            $payment->delete();
        });
    }

    public function getPayment(SupplierPayment $payment): SupplierPayment
    {
        return $payment->load(['supplier:id,name', 'purchaseOrder:id,po_number', 'account:id,code,name', 'recorder:id,name']);
    }

    /**
     * @param  array{from?: string, to?: string, purchase_order_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, SupplierPayment>
     */
    public function listPaymentsForSupplier(Supplier $supplier, array $filters = []): LengthAwarePaginator
    {
        return SupplierPayment::query()->with(['purchaseOrder:id,po_number', 'account:id,code,name', 'recorder:id,name'])
            ->where('supplier_id', $supplier->id)
            ->when(isset($filters['purchase_order_id']), static fn ($q) => $q->where('purchase_order_id', $filters['purchase_order_id']))
            ->when(isset($filters['from']), static fn ($q) => $q->whereDate('paid_at', '>=', $filters['from']))
            ->when(isset($filters['to']), static fn ($q) => $q->whereDate('paid_at', '<=', $filters['to']))
            ->orderByDesc('paid_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    /**
     * @return Collection<int, SupplierPayment>
     */
    public function listPaymentsForPurchaseOrder(PurchaseOrder $order): Collection
    {
        return SupplierPayment::query()->with(['account:id,code,name', 'recorder:id,name'])
            ->where('purchase_order_id', $order->id)->orderBy('paid_at')->orderBy('id')->get();
    }

    /**
     * Order total − Σ amount_paid on it − approved returns, in the order
     * currency (§49.4).
     */
    public function getBalanceForPurchaseOrder(PurchaseOrder $order): string
    {
        $paid = (string) (SupplierPayment::query()->where('purchase_order_id', $order->id)->sum('amount_paid') ?? '0');
        $returned = PurchaseReturn::query()->with('items')->where('purchase_order_id', $order->id)->whereIn('status', PurchaseReturn::APPROVED_STATES)->get()
            ->reduce(static fn (string $sum, PurchaseReturn $r): string => Money::add($sum, $r->value($order->currency_code)), Money::normalize(0));

        return Money::round(Money::sub(Money::sub($order->total(), Money::normalize($paid)), $returned), $order->currency_code);
    }

    /**
     * The supplier's running balance in the base currency: each owing
     * order's balance at its rate, minus unallocated payments (§49.4).
     */
    public function getSupplierBalance(Supplier $supplier): string
    {
        return $this->balances($supplier->id)[$supplier->id] ?? Money::normalize(0);
    }

    /**
     * Every supplier with a balance above zero, largest first (§49.6).
     *
     * @return BaseCollection<int, array{supplier_id: int, name: string, balance: string, currency_code: string}>
     */
    public function getOutstandingBalances(): BaseCollection
    {
        $balances = array_filter($this->balances(null), static fn (string $b): bool => Money::isPositive($b));
        $names = Supplier::withTrashed()->whereKey(array_keys($balances))->pluck('name', 'id');
        $base = $this->currencies->baseCurrency();

        return collect($balances)
            ->map(static fn (string $balance, int $id): array => ['supplier_id' => $id, 'name' => (string) $names->get($id), 'balance' => $balance, 'currency_code' => $base])
            ->sortByDesc(static fn (array $row): float => (float) $row['balance'])
            ->values();
    }

    /**
     * @return array<int, string> supplier_id => base-currency balance
     */
    private function balances(?int $supplierId): array
    {
        $db = DB::connection('tenant');
        $base = $this->currencies->baseCurrency();

        $totals = $db->table('purchase_orders as po')->join('purchase_order_items as i', 'i.purchase_order_id', '=', 'po.id')
            ->whereIn('po.status', self::OWING)
            ->when($supplierId !== null, static fn ($q) => $q->where('po.supplier_id', $supplierId))
            ->groupBy('po.id', 'po.supplier_id', 'po.exchange_rate_used')
            ->selectRaw('po.id, po.supplier_id, COALESCE(po.exchange_rate_used, 1) as rate, SUM(i.quantity_ordered * i.unit_cost) as total')
            ->get();

        $orderIds = $totals->pluck('id')->all();
        $paid = $orderIds === [] ? collect() : $db->table('supplier_payments')->whereIn('purchase_order_id', $orderIds)
            ->groupBy('purchase_order_id')->selectRaw('purchase_order_id, SUM(amount_paid) as paid')->pluck('paid', 'purchase_order_id');
        $returned = $orderIds === [] ? collect() : $db->table('purchase_returns as r')->join('purchase_return_items as ri', 'ri.purchase_return_id', '=', 'r.id')
            ->whereIn('r.purchase_order_id', $orderIds)->whereIn('r.status', PurchaseReturn::APPROVED_STATES)
            ->groupBy('r.purchase_order_id')->selectRaw('r.purchase_order_id, SUM(ri.quantity * ri.unit_cost) as returned')->pluck('returned', 'r.purchase_order_id');

        $balances = [];

        foreach ($totals as $row) {
            $owed = Money::sub(Money::sub(Money::normalize((string) $row->total), Money::normalize((string) ($paid[$row->id] ?? '0'))), Money::normalize((string) ($returned[$row->id] ?? '0')));
            $balances[(int) $row->supplier_id] = Money::add($balances[(int) $row->supplier_id] ?? Money::normalize(0), bcmul($owed, (string) $row->rate, CurrencyService::SCALE));
        }

        $unallocated = $db->table('supplier_payments')->whereNull('purchase_order_id')
            ->when($supplierId !== null, static fn ($q) => $q->where('supplier_id', $supplierId))
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(amount_paid * COALESCE(exchange_rate_used, 1)) as paid')->pluck('paid', 'supplier_id');

        foreach ($unallocated as $id => $amount) {
            $balances[(int) $id] = Money::sub($balances[(int) $id] ?? Money::normalize(0), Money::normalize((string) $amount));
        }

        return array_map(static fn (string $b): string => Money::round($b, $base), $balances);
    }

    private function post(SupplierPayment $payment, ?string $postingKey = null): void
    {
        $this->outbox->record('postSupplierPayment', $payment, $payment->paid_at, $postingKey ?? 'supplier_payment:'.$payment->id, [
            'amount' => (string) $payment->amount_paid,
            'account_id' => $payment->account_id,
            'rate' => (string) ($payment->exchange_rate_used ?? '1'),
        ]);
    }

    /**
     * Reverse the current posting and post afresh as the next version;
     * nothing when the payment was never posted (accounting was off).
     */
    private function repost(SupplierPayment $payment, string $reason): void
    {
        $base = 'supplier_payment:'.$payment->id;
        $current = $this->outbox->currentKey($base);

        if ($current === null) {
            return;
        }

        $version = $this->outbox->nextVersion($base);
        $this->outbox->record('reversePosting', $payment, now(), 'supplier_payment_reversal:'.$payment->id.':'.$version, ['posting_key' => $current, 'reason' => $reason]);
        $this->post($payment, $base.':v'.$version);
    }

    private function assertManual(SupplierPayment $payment): void
    {
        if ($payment->purchase_return_id !== null) {
            throw ApiException::unprocessable('supplier_refund_locked', 'A supplier refund belongs to its purchase return and cannot be changed here.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, bool $creating, bool $refund): array
    {
        $validated = Validator::make($data, [
            'amount_paid' => [$creating ? 'required' : 'sometimes', 'numeric', $refund ? 'lt:0' : 'gt:0', 'decimal:0,4', 'min:-99999999999999', 'max:99999999999999'],
            'payment_method' => [$creating ? 'required' : 'sometimes', Rule::in(SupplierPayment::METHODS)],
            'account_id' => ['sometimes', 'nullable', 'integer'],
            'paid_at' => ['sometimes', 'date'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'amount_due' => ['sometimes', 'nullable', 'numeric', 'decimal:0,4'],
            'amount_received' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'change_given' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,4'],
        ])->validate();

        if (isset($validated['account_id'])) {
            $account = Account::query()->with('category')->find($validated['account_id']);

            if ($account === null || ! $account->is_active || $account->category->account_type !== 'asset') {
                throw ApiException::unprocessable('account_invalid', 'Pay from an active asset account (cash or bank).');
            }
        }

        return $validated;
    }
}
