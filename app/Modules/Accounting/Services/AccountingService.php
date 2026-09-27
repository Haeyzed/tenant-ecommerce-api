<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Exceptions\PostingException;
use App\Modules\Accounting\Jobs\PostAccountingEntry;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountBalance;
use App\Modules\Accounting\Models\AccountCategory;
use App\Modules\Accounting\Models\AccountingPostingRequest;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Catalog\Models\Product;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\IncomeEntry;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockAdjustmentItem;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The general ledger (spec §57): every automatic posting is defined here
 * once, reached only through the outbox (PostAccountingEntry) or a manual
 * entry. Entries are balanced by construction, base-currency, posted into
 * an open period and immutable; corrections are reversals.
 */
final class AccountingService
{
    /** @var array<string, Account> */
    private array $accounts = [];

    public function __construct(
        private readonly TenantSettingsService $settings,
        private readonly NotificationDispatchService $notifications,
    ) {}

    // ---- The outbox ------------------------------------------------------

    /**
     * Posts one outbox request (§57.3 step 3). Pending and failed requests
     * are processed; posted and skipped ones are left alone.
     */
    public function processRequest(int $requestId): void
    {
        $failure = null;

        DB::connection('tenant')->transaction(function () use ($requestId, &$failure): void {
            /** @var AccountingPostingRequest|null $request */
            $request = AccountingPostingRequest::query()->lockForUpdate()->find($requestId);

            if ($request === null || ! in_array($request->status, [AccountingPostingRequest::PENDING, AccountingPostingRequest::FAILED], true)) {
                return;
            }

            $request->attempts++;

            try {
                $entry = DB::connection('tenant')->transaction(fn (): ?JournalEntry => $this->dispatchPosting($request));
            } catch (PostingException $e) {
                $request->forceFill(['status' => AccountingPostingRequest::FAILED, 'last_error' => mb_substr($e->getMessage(), 0, 2000), 'processed_at' => now()])->save();
                $failure = [$request, $e->getMessage()];

                return;
            } catch (Throwable $e) {
                report($e);
                $request->forceFill(['status' => AccountingPostingRequest::FAILED, 'last_error' => 'Unexpected error: '.mb_substr($e->getMessage(), 0, 1900), 'processed_at' => now()])->save();
                $failure = [$request, 'an unexpected error'];

                return;
            }

            $request->forceFill([
                'status' => $entry === null ? AccountingPostingRequest::SKIPPED : AccountingPostingRequest::POSTED,
                'journal_entry_id' => $entry?->id,
                'last_error' => null,
                'processed_at' => now(),
            ])->save();
        });

        if ($failure !== null) {
            [$request, $reason] = $failure;
            $this->notifications->dispatch('accounting.posting_failed', $request, [
                'source' => str_replace('_', ' ', (string) $request->reference_type).' #'.$request->reference_id,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * @return int the number of requests re-dispatched
     */
    public function retryFailed(): int
    {
        $ids = AccountingPostingRequest::query()->where('status', AccountingPostingRequest::FAILED)->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            PostAccountingEntry::dispatch((string) tenant()?->getTenantKey(), (int) $id);
        }

        return $ids->count();
    }

    /**
     * The daily recovery (§57.3 step 5): pending requests older than ten
     * minutes whose dispatch was lost.
     */
    public function redispatchStale(): int
    {
        $ids = AccountingPostingRequest::query()->where('status', AccountingPostingRequest::PENDING)
            ->where('created_at', '<', now()->subMinutes(10))->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            PostAccountingEntry::dispatch((string) tenant()?->getTenantKey(), (int) $id);
        }

        return $ids->count();
    }

    private function dispatchPosting(AccountingPostingRequest $request): ?JournalEntry
    {
        $date = Carbon::parse($request->event_date->toDateString());

        if ($request->method === 'reversePosting') {
            return $this->reversePosting($request, $date);
        }

        $record = $this->resolve($request);

        if ($record === null) {
            return null;
        }

        return match ($request->method) {
            'postOrderSale' => $this->postOrderSale($record, $request, $date),
            'reverseOrderSale' => $this->reverseOrderSale($record, $request, $date),
            'postOrderPayment' => $this->postOrderPayment($record, $request, $date),
            'postGatewayFee' => $this->postGatewayFee($record, $request, $date),
            'postRefund', 'postChargeback' => $this->postRefund($record, $request, $date),
            'postReturnRestock' => $this->postReturnRestock($record, $request, $date),
            'postStockAdjustment' => $this->postStockAdjustment($record, $request, $date),
            'postExpense' => $this->postExpense($record, $request, $date),
            'postIncome' => $this->postIncome($record, $request, $date),
            'postPurchaseOrderReceived' => $this->postPurchaseOrderReceived($record, $request, $date),
            'postSupplierPayment' => $this->postSupplierPayment($record, $request, $date),
            'postPurchaseReturn' => $this->postPurchaseReturn($record, $request, $date),
            default => throw new PostingException("Unknown posting method [{$request->method}]."),
        };
    }

    private function resolve(AccountingPostingRequest $request): ?Model
    {
        $class = Relation::getMorphedModel($request->reference_type) ?? $request->reference_type;

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $query = $class::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }

        return $query->find($request->reference_id);
    }

    // ---- Automatic postings (§57.3) --------------------------------------

    /**
     * Revenue net of promotion and point discounts, shipping, tax and the
     * receivable; cost of goods for physical lines. A gift-card purchase is
     * a liability, not a sale.
     */
    private function postOrderSale(Model $order, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $order instanceof Order || $order->confirmed_at === null) {
            return null;
        }

        $rate = (string) ($order->exchange_rate_used ?? '1');
        $lines = [];

        if ($order->order_type === 'gift_card_purchase') {
            $total = $this->base((string) $order->total, $rate);
            $lines[] = [$this->system('accounts_receivable'), JournalEntryLine::DEBIT, $total, null];
            $lines[] = [$this->system('gift_card_liability'), JournalEntryLine::CREDIT, $total, null];
        } else {
            $tax = $this->base((string) $order->tax_amount, $rate);
            $shippingNet = Money::sub((string) $order->shipping_amount, (string) $order->shipping_discount_amount);
            $shipping = $this->base($order->prices_include_tax ? Money::sub($shippingNet, (string) $order->shipping_tax_amount) : $shippingNet, $rate);
            // Revenue is what remains of the total: net merchandise after
            // promotion and point discounts, exclusive of tax (§38.5).
            $sales = Money::sub(Money::sub($this->base((string) $order->total, $rate), $tax), $shipping);

            $lines[] = [$this->system('sales_revenue'), JournalEntryLine::CREDIT, $sales, null];
            $lines[] = [$this->system('shipping_revenue'), JournalEntryLine::CREDIT, $shipping, null];
            $lines[] = [$this->system('tax_payable'), JournalEntryLine::CREDIT, $tax, null];
            $lines[] = [$this->system('accounts_receivable'), JournalEntryLine::DEBIT, Money::add(Money::add($sales, $shipping), $tax), null];

            $cost = Money::normalize(0);

            foreach (OrderItem::query()->with('product')->where('order_id', $order->id)->get() as $item) {
                if ($item->unit_cost_snapshot !== null && $item->product !== null && in_array($item->product->product_type, Product::PHYSICAL, true)) {
                    $cost = Money::add($cost, Money::mul((string) $item->unit_cost_snapshot, (string) $item->quantity));
                }
            }

            $cost = $this->base($cost, $rate);
            $lines[] = [$this->system('cost_of_goods_sold'), JournalEntryLine::DEBIT, $cost, 'Cost of goods sold'];
            $lines[] = [$this->system('inventory_asset'), JournalEntryLine::CREDIT, $cost, 'Cost of goods sold'];
        }

        return $this->write($request, $date, 'Sale, order '.$order->order_number, $order, $lines);
    }

    /**
     * A cancelled confirmed order (§39.5): the sale entry is reversed. When
     * the sale never posted, both requests cancel out.
     */
    private function reverseOrderSale(Model $order, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $order instanceof Order) {
            return null;
        }

        return $this->reverseKey('order_sale:'.$order->id, $request, $date, 'Cancelled order '.$order->order_number);
    }

    /**
     * Cr Accounts Receivable; Dr Cash (the row's account, else cash_bank),
     * Gift Card Liability or Sales Returns by method. Amounts come from the
     * request payload, snapshotted when the payment was recorded.
     */
    private function postOrderPayment(Model $payment, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $payment instanceof OrderPayment) {
            return null;
        }

        $payload = (array) $request->payload;
        $order = Order::withTrashed()->find($payment->order_id);
        $amount = $this->base((string) ($payload['amount'] ?? $payment->amount_paid), (string) ($order->exchange_rate_used ?? '1'));
        $method = (string) ($payload['payment_method'] ?? $payment->payment_method);

        $debit = match ($method) {
            'gift_card' => $this->system('gift_card_liability'),
            'exchange_credit' => $this->system('sales_returns'),
            default => $this->cash($payload['account_id'] ?? $payment->account_id),
        };

        return $this->write($request, $date, 'Payment '.$payment->reference.', order '.$order?->order_number, $payment, [
            [$debit, JournalEntryLine::DEBIT, $amount, null],
            [$this->system('accounts_receivable'), JournalEntryLine::CREDIT, $amount, null],
        ]);
    }

    private function postGatewayFee(Model $payment, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $payment instanceof OrderPayment) {
            return null;
        }

        $order = Order::withTrashed()->find($payment->order_id);
        $fee = $this->base((string) ((array) $request->payload)['fee'], (string) ($order->exchange_rate_used ?? '1'));

        return $this->write($request, $date, 'Processing fee, payment '.$payment->reference, $payment, [
            [$this->system('payment_processing_fees'), JournalEntryLine::DEBIT, $fee, null],
            [$this->cash($payment->account_id), JournalEntryLine::CREDIT, $fee, null],
        ]);
    }

    /**
     * Refunds and chargebacks: Dr Sales Returns (Gift Card Liability for a
     * gift-card purchase) net of tax, Dr Tax Payable for the tax share,
     * Cr Cash (Gift Card Liability for a gift-card refund). Tax share: a
     * return's refunded lines pro-rated by quantity, else the order's tax
     * ratio (A-44).
     */
    private function postRefund(Model $refund, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $refund instanceof OrderPayment || $refund->status !== OrderPayment::SUCCESSFUL) {
            return null;
        }

        $order = Order::withTrashed()->findOrFail($refund->order_id);
        $rate = (string) ($order->exchange_rate_used ?? '1');
        $amount = Money::sub('0', (string) $refund->amount_paid);

        if ($refund->order_return_id !== null && $refund->kind === OrderPayment::REFUND) {
            $tax = Money::normalize(0);

            foreach (OrderReturn::query()->with('items.orderItem')->find($refund->order_return_id)?->items ?? [] as $item) {
                $line = $item->orderItem;

                if ($line !== null && Money::isPositive((string) $line->quantity)) {
                    $tax = Money::add($tax, Money::div(Money::mul((string) $line->tax_amount, (string) $item->quantity), (string) $line->quantity));
                }
            }

            $tax = Money::min($tax, $amount);
        } else {
            $tax = Money::isPositive((string) $order->total) ? Money::div(Money::mul($amount, (string) $order->tax_amount), (string) $order->total) : Money::normalize(0);
        }

        $amount = $this->base($amount, $rate);
        $tax = $this->base($tax, $rate);
        $giftCardSale = $order->order_type === 'gift_card_purchase';
        $label = $refund->kind === OrderPayment::CHARGEBACK ? 'Chargeback' : 'Refund';

        return $this->write($request, $date, $label.' '.$refund->reference.', order '.$order->order_number, $refund, [
            [$this->system($giftCardSale ? 'gift_card_liability' : 'sales_returns'), JournalEntryLine::DEBIT, Money::sub($amount, $tax), null],
            [$this->system('tax_payable'), JournalEntryLine::DEBIT, $tax, null],
            [$refund->payment_method === 'gift_card' ? $this->system('gift_card_liability') : $this->cash($refund->account_id), JournalEntryLine::CREDIT, $amount, null],
        ]);
    }

    /**
     * Dr Inventory Asset, Cr Cost of Goods Sold for the restocked cost,
     * snapshotted in the payload when the stock came back.
     */
    private function postReturnRestock(Model $return, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $return instanceof OrderReturn) {
            return null;
        }

        $order = Order::withTrashed()->find($return->order_id);
        $value = $this->base((string) (((array) $request->payload)['cost'] ?? '0'), (string) ($order->exchange_rate_used ?? '1'));

        return $this->write($request, $date, 'Restock, return '.$return->return_number, $return, [
            [$this->system('inventory_asset'), JournalEntryLine::DEBIT, $value, null],
            [$this->system('cost_of_goods_sold'), JournalEntryLine::CREDIT, $value, null],
        ]);
    }

    /**
     * Shrinkage and write-ups at the snapshotted unit cost; lines without a
     * cost are named in the description and not posted.
     */
    private function postStockAdjustment(Model $adjustment, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $adjustment instanceof StockAdjustment) {
            return null;
        }

        $out = $in = Money::normalize(0);
        $uncosted = [];

        foreach (StockAdjustmentItem::query()->with('product:id,name')->where('stock_adjustment_id', $adjustment->id)->get() as $item) {
            if ($item->unit_cost_snapshot === null) {
                $uncosted[] = $item->product->name ?? ('product #'.$item->product_id);

                continue;
            }

            $value = $this->round(Money::mul((string) $item->quantity, (string) $item->unit_cost_snapshot));
            $item->action === StockAdjustmentItem::ADDITION ? $in = Money::add($in, $value) : $out = Money::add($out, $value);
        }

        $description = 'Stock adjustment #'.$adjustment->id.($uncosted === [] ? '' : ' (not valued: '.mb_substr(implode(', ', $uncosted), 0, 150).')');

        return $this->write($request, $date, $description, $adjustment, [
            [$this->system('inventory_adjustments'), JournalEntryLine::DEBIT, $out, 'Shrinkage and write-offs'],
            [$this->system('inventory_asset'), JournalEntryLine::CREDIT, $out, 'Shrinkage and write-offs'],
            [$this->system('inventory_asset'), JournalEntryLine::DEBIT, $in, 'Stock found'],
            [$this->system('inventory_adjustments'), JournalEntryLine::CREDIT, $in, 'Stock found'],
        ]);
    }

    private function postExpense(Model $expense, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $expense instanceof Expense || $expense->status !== Expense::PAID) {
            return null;
        }

        $expense->loadMissing('category');
        $amount = $this->base((string) $expense->amount, (string) ($expense->exchange_rate_used ?? '1'));
        $debit = $expense->category->account_id === null ? $this->system('general_expenses') : Account::query()->findOrFail($expense->category->account_id);

        return $this->write($request, $date, 'Expense: '.$expense->category->name, $expense, [
            [$debit, JournalEntryLine::DEBIT, $amount, $expense->description === null ? null : mb_substr($expense->description, 0, 255)],
            [$this->cash($expense->paid_from_account_id), JournalEntryLine::CREDIT, $amount, null],
        ]);
    }

    /**
     * Dr Inventory Asset, Cr Accounts Payable for one receipt (§49.2). The
     * received value (order currency) and the order's rate are snapshotted
     * in the payload.
     */
    private function postPurchaseOrderReceived(Model $order, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $order instanceof PurchaseOrder) {
            return null;
        }

        $payload = (array) $request->payload;
        $value = $this->base((string) ($payload['value'] ?? '0'), (string) ($payload['rate'] ?? $order->toBaseRate()));

        return $this->write($request, $date, 'Stock received, purchase order '.$order->po_number, $order, [
            [$this->system('inventory_asset'), JournalEntryLine::DEBIT, $value, null],
            [$this->system('accounts_payable'), JournalEntryLine::CREDIT, $value, null],
        ]);
    }

    /**
     * Dr Accounts Payable, Cr Cash (the row's account, else cash_bank); a
     * negative amount (the supplier refunded) reverses the sides (§49.4).
     */
    private function postSupplierPayment(Model $payment, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $payment instanceof SupplierPayment) {
            return null;
        }

        $payload = (array) $request->payload;
        $amount = Money::normalize((string) ($payload['amount'] ?? $payment->amount_paid));
        $refund = Money::cmp($amount, '0') < 0;
        $value = $this->base($refund ? Money::sub('0', $amount) : $amount, (string) ($payload['rate'] ?? $payment->exchange_rate_used ?? '1'));
        $cash = $this->cash($payload['account_id'] ?? $payment->account_id);
        $payable = $this->system('accounts_payable');

        return $this->write($request, $date, ($refund ? 'Supplier refund' : 'Supplier payment').($payment->reference === null ? '' : ' '.$payment->reference), $payment, [
            [$refund ? $cash : $payable, JournalEntryLine::DEBIT, $value, null],
            [$refund ? $payable : $cash, JournalEntryLine::CREDIT, $value, null],
        ]);
    }

    /**
     * Dr Accounts Payable, Cr Inventory Asset for the returned value (§49.5).
     */
    private function postPurchaseReturn(Model $return, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $return instanceof PurchaseReturn) {
            return null;
        }

        $payload = (array) $request->payload;
        $value = $this->base((string) ($payload['value'] ?? '0'), (string) ($payload['rate'] ?? '1'));

        return $this->write($request, $date, 'Purchase return '.$return->number(), $return, [
            [$this->system('accounts_payable'), JournalEntryLine::DEBIT, $value, null],
            [$this->system('inventory_asset'), JournalEntryLine::CREDIT, $value, null],
        ]);
    }

    private function postIncome(Model $income, AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        if (! $income instanceof IncomeEntry || $income->status !== IncomeEntry::RECEIVED) {
            return null;
        }

        $income->loadMissing('category');
        $amount = $this->base((string) $income->amount, (string) ($income->exchange_rate_used ?? '1'));
        $credit = $income->category->account_id === null ? $this->system('other_income') : Account::query()->findOrFail($income->category->account_id);

        return $this->write($request, $date, 'Income: '.$income->category->name, $income, [
            [$this->cash($income->received_into_account_id), JournalEntryLine::DEBIT, $amount, $income->source === null ? null : mb_substr($income->source, 0, 255)],
            [$credit, JournalEntryLine::CREDIT, $amount, null],
        ]);
    }

    /**
     * Reverses the entry of another posting key (payload.posting_key): an
     * edited or deleted manual payment (§57.3).
     */
    private function reversePosting(AccountingPostingRequest $request, Carbon $date): ?JournalEntry
    {
        return $this->reverseKey((string) ((array) $request->payload)['posting_key'], $request, $date, (string) (((array) $request->payload)['reason'] ?? 'Correction'));
    }

    private function reverseKey(string $postingKey, AccountingPostingRequest $request, Carbon $date, string $reason): ?JournalEntry
    {
        /** @var JournalEntry|null $original */
        $original = JournalEntry::query()->where('posting_key', $postingKey)->lockForUpdate()->first();

        if ($original === null) {
            // Never posted: its request is withdrawn, and nothing is reversed.
            AccountingPostingRequest::query()->where('posting_key', $postingKey)
                ->whereIn('status', [AccountingPostingRequest::PENDING, AccountingPostingRequest::FAILED])
                ->update(['status' => AccountingPostingRequest::SKIPPED, 'last_error' => 'Withdrawn by '.$request->posting_key, 'processed_at' => now(), 'updated_at' => now()]);

            return null;
        }

        return $original->reversed_at !== null ? null : $this->reversal($original, $date, $reason, null, $request->posting_key);
    }

    // ---- Manual entries and reversals (§57.7) -----------------------------

    /**
     * @param  array<string, mixed>  $data  entry_date, description, cash_flow_category, lines[{account_id, type, amount, description}]
     */
    public function postManualEntry(array $data, ?User $by = null): JournalEntry
    {
        $validated = validator($data, [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'cash_flow_category' => ['sometimes', Rule::in(JournalEntry::CASH_FLOW_CATEGORIES)],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('tenant.chart_of_accounts', 'id')->where('is_active', true)],
            'lines.*.type' => ['required', Rule::in([JournalEntryLine::DEBIT, JournalEntryLine::CREDIT])],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'max:99999999999999'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ])->validate();

        $accounts = Account::query()->whereIn('id', array_column($validated['lines'], 'account_id'))->get()->keyBy('id');
        $lines = array_map(fn (array $l): array => [$accounts[(int) $l['account_id']], $l['type'], $this->round((string) $l['amount']), $l['description'] ?? null], $validated['lines']);

        try {
            return DB::connection('tenant')->transaction(fn (): JournalEntry => $this->insert(
                Carbon::parse($validated['entry_date']), $validated['description'], JournalEntry::MANUAL, null, null, $lines,
                (string) ($validated['cash_flow_category'] ?? 'operating'), $by,
            ));
        } catch (PostingException $e) {
            throw ApiException::unprocessable('posting_rejected', $e->getMessage());
        }
    }

    /**
     * The equal and opposite entry dated today (§57.7). An entry is
     * reversed once; a reversal is not itself reversed.
     */
    public function reverseEntry(JournalEntry $entry, ?string $reason = null, ?User $by = null): JournalEntry
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($entry, $reason, $by): JournalEntry {
                /** @var JournalEntry $locked */
                $locked = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);

                if ($locked->reversed_at !== null || $locked->source === JournalEntry::REVERSAL) {
                    throw ApiException::unprocessable('entry_not_reversible', 'This entry has already been reversed, or is itself a reversal.');
                }

                return $this->reversal($locked, $this->today(), $reason ?? 'Reversal', $by, null);
            });
        } catch (PostingException $e) {
            throw ApiException::unprocessable('posting_rejected', $e->getMessage());
        }
    }

    private function reversal(JournalEntry $original, Carbon $date, string $reason, ?User $by, ?string $postingKey): JournalEntry
    {
        $original->loadMissing('lines.account.category');
        $lines = $original->lines->map(static fn (JournalEntryLine $l): array => [
            $l->account, $l->type === JournalEntryLine::DEBIT ? JournalEntryLine::CREDIT : JournalEntryLine::DEBIT, (string) $l->amount, $l->description,
        ])->all();

        $entry = $this->insert($date, mb_substr('Reversal of #'.$original->id.': '.$reason, 0, 255), JournalEntry::REVERSAL, $original->reference_type,
            $original->reference_id, $lines, $original->cash_flow_category, $by, $postingKey, $original->id);

        $original->forceFill(['reversed_at' => now()])->save();

        return $entry;
    }

    // ---- Writing ----------------------------------------------------------

    /**
     * @param  list<array{0: Account, 1: string, 2: string, 3: string|null}>  $lines
     */
    private function write(AccountingPostingRequest $request, Carbon $date, string $description, Model $reference, array $lines): ?JournalEntry
    {
        $lines = array_values(array_filter($lines, static fn (array $l): bool => Money::isPositive($l[2])));

        if ($lines === []) {
            return null;
        }

        return $this->insert($date, mb_substr($description, 0, 255), JournalEntry::SYSTEM, $reference->getMorphClass(), (int) $reference->getKey(), $lines,
            'operating', null, $request->posting_key);
    }

    /**
     * @param  list<array{0: Account, 1: string, 2: string, 3: string|null}>  $lines
     */
    private function insert(Carbon $date, string $description, string $source, ?string $referenceType, ?int $referenceId, array $lines,
        string $cashFlow, ?User $by, ?string $postingKey = null, ?int $reverses = null): JournalEntry
    {
        $lines = array_values(array_filter($lines, static fn (array $l): bool => Money::isPositive($l[2])));
        $debits = $credits = Money::normalize(0);

        foreach ($lines as [, $type, $amount]) {
            $type === JournalEntryLine::DEBIT ? $debits = Money::add($debits, $amount) : $credits = Money::add($credits, $amount);
        }

        if (count($lines) < 2 || Money::cmp($debits, $credits) !== 0) {
            throw new PostingException("The entry does not balance: debits {$debits}, credits {$credits}.");
        }

        $period = $this->openPeriod($date);

        $entry = new JournalEntry;
        $entry->forceFill([
            'entry_date' => $date->toDateString(),
            'fiscal_period_id' => $period->id,
            'description' => $description,
            'source' => $source,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'posting_key' => $postingKey,
            'cash_flow_category' => $cashFlow,
            'reverses_journal_entry_id' => $reverses,
            'created_by_user_id' => $by?->id,
        ])->save();

        foreach ($lines as [$account, $type, $amount, $lineDescription]) {
            $line = new JournalEntryLine;
            $line->forceFill([
                'journal_entry_id' => $entry->id,
                'account_id' => $account->id,
                'type' => $type,
                'amount' => $amount,
                'description' => $lineDescription === null ? null : mb_substr($lineDescription, 0, 255),
            ])->save();

            $this->addToBalance($account, $period, $type, $amount);
        }

        return $entry->load('lines');
    }

    private function openPeriod(Carbon $date): FiscalPeriod
    {
        $period = FiscalPeriod::query()->with('year')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();

        if ($period === null) {
            throw new PostingException("No fiscal period covers {$date->toDateString()}. Create it, then retry the posting.");
        }

        if ($period->status !== FiscalPeriod::OPEN) {
            throw new PostingException("The fiscal period {$period->name} is closed. Reopen it or post into an open period.");
        }

        return $period;
    }

    /**
     * Keeps the balance cache current as lines post (§57.3).
     */
    private function addToBalance(Account $account, FiscalPeriod $period, string $type, string $amount): void
    {
        $balance = AccountBalance::query()->where('account_id', $account->id)->where('fiscal_period_id', $period->id)->lockForUpdate()->first();

        if ($balance === null) {
            $balance = new AccountBalance;
            $balance->forceFill([
                'account_id' => $account->id,
                'fiscal_period_id' => $period->id,
                'opening_balance' => $this->openingBalance($account, $period),
                'debit_total' => '0',
                'credit_total' => '0',
            ]);
        }

        $balance->forceFill($type === JournalEntryLine::DEBIT
            ? ['debit_total' => Money::add((string) $balance->debit_total, $amount)]
            : ['credit_total' => Money::add((string) $balance->credit_total, $amount)]);
        $balance->forceFill(['closing_balance' => $this->closing($account, (string) $balance->opening_balance, (string) $balance->debit_total, (string) $balance->credit_total)])->save();
    }

    /**
     * Rebuilds one period's cached balance from the ledger (§57.7).
     */
    public function recalculateAccountBalance(Account $account, FiscalPeriod $period): AccountBalance
    {
        $sums = JournalEntryLine::query()->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.account_id', $account->id)->where('je.fiscal_period_id', $period->id)
            ->selectRaw("SUM(CASE WHEN journal_entry_lines.type = 'debit' THEN journal_entry_lines.amount ELSE 0 END) as debits,"
                ." SUM(CASE WHEN journal_entry_lines.type = 'credit' THEN journal_entry_lines.amount ELSE 0 END) as credits")
            ->first();

        $opening = $this->openingBalance($account, $period);
        $debits = Money::normalize((string) ($sums->debits ?? '0'));
        $credits = Money::normalize((string) ($sums->credits ?? '0'));

        /** @var AccountBalance $balance */
        $balance = AccountBalance::query()->firstOrNew(['account_id' => $account->id, 'fiscal_period_id' => $period->id]);
        $balance->forceFill([
            'account_id' => $account->id,
            'fiscal_period_id' => $period->id,
            'opening_balance' => $opening,
            'debit_total' => $debits,
            'credit_total' => $credits,
            'closing_balance' => $this->closing($account, $opening, $debits, $credits),
        ])->save();

        return $balance;
    }

    /**
     * The balance of every line before the period, on the account's normal
     * side.
     */
    private function openingBalance(Account $account, FiscalPeriod $period): string
    {
        $sums = JournalEntryLine::query()->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.account_id', $account->id)->where('je.entry_date', '<', $period->starts_on->toDateString())
            ->selectRaw("SUM(CASE WHEN journal_entry_lines.type = 'debit' THEN journal_entry_lines.amount ELSE 0 END) as debits,"
                ." SUM(CASE WHEN journal_entry_lines.type = 'credit' THEN journal_entry_lines.amount ELSE 0 END) as credits")
            ->first();

        return $this->closing($account, '0', (string) ($sums->debits ?? '0'), (string) ($sums->credits ?? '0'));
    }

    private function closing(Account $account, string $opening, string $debits, string $credits): string
    {
        $account->loadMissing('category');

        return $account->isDebitNormal()
            ? Money::add($opening, Money::sub($debits, $credits))
            : Money::add($opening, Money::sub($credits, $debits));
    }

    // ---- Reports (§57.6) ----------------------------------------------------

    /**
     * @return array{from: string, to: string, currency: string, revenue: array<string, mixed>, expenses: array<string, mixed>, net_profit: string}
     */
    public function getProfitAndLoss(Carbon $from, Carbon $to): array
    {
        $balances = $this->balancesBetween($from, $to);
        $revenue = $this->section($balances, 'revenue');
        $expenses = $this->section($balances, 'expense');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $this->currency(),
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_profit' => Money::sub($revenue['total'], $expenses['total']),
        ];
    }

    /**
     * @return array{as_of: string, currency: string, assets: array<string, mixed>, liabilities: array<string, mixed>, equity: array<string, mixed>, accumulated_earnings: string, total_liabilities_and_equity: string, balanced: bool}
     */
    public function getBalanceSheet(Carbon $asOf): array
    {
        $balances = $this->balancesBetween(null, $asOf);
        $assets = $this->section($balances, 'asset');
        $liabilities = $this->section($balances, 'liability');
        $equity = $this->section($balances, 'equity');
        $earnings = Money::sub($this->section($balances, 'revenue')['total'], $this->section($balances, 'expense')['total']);
        $right = Money::add(Money::add($liabilities['total'], $equity['total']), $earnings);

        return [
            'as_of' => $asOf->toDateString(),
            'currency' => $this->currency(),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'accumulated_earnings' => $earnings,
            'total_liabilities_and_equity' => $right,
            'balanced' => Money::cmp($assets['total'], $right) === 0,
        ];
    }

    /**
     * @return array{as_of: string, currency: string, accounts: list<array<string, mixed>>, total_debits: string, total_credits: string, balanced: bool}
     */
    public function getTrialBalance(Carbon $asOf): array
    {
        $rows = [];
        $debits = $credits = Money::normalize(0);

        foreach ($this->balancesBetween(null, $asOf) as $row) {
            $net = Money::sub($row['debits'], $row['credits']);
            $debit = Money::isPositive($net) ? $net : Money::normalize(0);
            $credit = Money::isPositive(Money::sub('0', $net)) ? Money::sub('0', $net) : Money::normalize(0);
            $debits = Money::add($debits, $debit);
            $credits = Money::add($credits, $credit);
            $rows[] = ['account_id' => $row['account']->id, 'code' => $row['account']->code, 'name' => $row['account']->name,
                'account_type' => $row['account']->category->account_type, 'debit' => $debit, 'credit' => $credit];
        }

        return ['as_of' => $asOf->toDateString(), 'currency' => $this->currency(), 'accounts' => $rows,
            'total_debits' => $debits, 'total_credits' => $credits, 'balanced' => Money::cmp($debits, $credits) === 0];
    }

    /**
     * Every line of one account in the range, with a running balance on the
     * account's normal side, after the opening balance.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getGeneralLedger(Account $account, Carbon $from, Carbon $to): Collection
    {
        $account->loadMissing('category');
        $opening = $this->balanceOf($account, null, $from->copy()->subDay());
        $running = $opening;

        $lines = JournalEntryLine::query()->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.account_id', $account->id)
            ->whereBetween('je.entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('je.entry_date')->orderBy('je.id')->orderBy('journal_entry_lines.id')
            ->get(['journal_entry_lines.*', 'je.entry_date', 'je.description as entry_description', 'je.source', 'je.reference_type', 'je.reference_id'])
            ->map(function (JournalEntryLine $line) use ($account, &$running): array {
                $running = $this->closing($account, $running, $line->type === JournalEntryLine::DEBIT ? (string) $line->amount : '0', $line->type === JournalEntryLine::CREDIT ? (string) $line->amount : '0');

                return [
                    'journal_entry_id' => $line->journal_entry_id,
                    'entry_date' => Carbon::parse((string) $line->getAttribute('entry_date'))->toDateString(),
                    'description' => $line->description ?? $line->getAttribute('entry_description'),
                    'source' => $line->getAttribute('source'),
                    'reference_type' => $line->getAttribute('reference_type'),
                    'reference_id' => $line->getAttribute('reference_id') === null ? null : (int) $line->getAttribute('reference_id'),
                    'debit' => $line->type === JournalEntryLine::DEBIT ? (string) $line->amount : null,
                    'credit' => $line->type === JournalEntryLine::CREDIT ? (string) $line->amount : null,
                    'balance' => $running,
                ];
            });

        return collect([['opening_balance' => $opening]])->concat($lines)->values();
    }

    /**
     * Cash accounts (cash_bank, its sub-accounts and accounts under them)
     * by cash-flow category: inflows are debits, outflows credits.
     *
     * @return array{from: string, to: string, currency: string, categories: array<string, array{inflow: string, outflow: string, net: string}>, net_change: string}
     */
    public function getCashFlowStatement(Carbon $from, Carbon $to): array
    {
        $cashIds = $this->cashAccountIds();
        $rows = JournalEntryLine::query()->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entry_lines.account_id', $cashIds === [] ? [0] : $cashIds)
            ->whereBetween('je.entry_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('je.cash_flow_category')
            ->selectRaw("je.cash_flow_category as category, SUM(CASE WHEN journal_entry_lines.type = 'debit' THEN journal_entry_lines.amount ELSE 0 END) as inflow,"
                ." SUM(CASE WHEN journal_entry_lines.type = 'credit' THEN journal_entry_lines.amount ELSE 0 END) as outflow")
            ->get()->keyBy('category');

        $categories = [];
        $net = Money::normalize(0);

        foreach (JournalEntry::CASH_FLOW_CATEGORIES as $category) {
            $in = Money::normalize((string) ($rows[$category]->inflow ?? '0'));
            $out = Money::normalize((string) ($rows[$category]->outflow ?? '0'));
            $categories[$category] = ['inflow' => $in, 'outflow' => $out, 'net' => Money::sub($in, $out)];
            $net = Money::add($net, Money::sub($in, $out));
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => $this->currency(), 'categories' => $categories, 'net_change' => $net];
    }

    /**
     * @return list<int>
     */
    public function cashAccountIds(): array
    {
        $ids = [$this->system('cash_bank')->id];
        $frontier = $ids;

        while ($frontier !== []) {
            $children = Account::query()->whereIn('parent_account_id', $frontier)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $frontier = array_values(array_diff($children, $ids));
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    /**
     * Debit and credit sums per account over entry dates (open-ended when
     * $from is null), for accounts with activity.
     *
     * @return list<array{account: Account, debits: string, credits: string}>
     */
    private function balancesBetween(?Carbon $from, Carbon $to): array
    {
        $sums = JournalEntryLine::query()->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->when($from !== null, static fn ($q) => $q->where('je.entry_date', '>=', $from?->toDateString()))
            ->where('je.entry_date', '<=', $to->toDateString())
            ->groupBy('journal_entry_lines.account_id')
            ->selectRaw("journal_entry_lines.account_id, SUM(CASE WHEN journal_entry_lines.type = 'debit' THEN journal_entry_lines.amount ELSE 0 END) as debits,"
                ." SUM(CASE WHEN journal_entry_lines.type = 'credit' THEN journal_entry_lines.amount ELSE 0 END) as credits")
            ->get()->keyBy('account_id');

        return Account::query()->with('category')->whereIn('id', $sums->keys())->orderBy('code')->get()
            ->map(static fn (Account $a): array => ['account' => $a, 'debits' => Money::normalize((string) $sums[$a->id]->debits), 'credits' => Money::normalize((string) $sums[$a->id]->credits)])
            ->all();
    }

    /**
     * @param  list<array{account: Account, debits: string, credits: string}>  $balances
     * @return array{categories: list<array<string, mixed>>, total: string}
     */
    private function section(array $balances, string $type): array
    {
        $categories = [];
        $total = Money::normalize(0);

        foreach ($balances as $row) {
            $account = $row['account'];

            if ($account->category->account_type !== $type) {
                continue;
            }

            // Every account of the type is reported on the type's normal
            // side, so a contra account (sales returns) reduces the total.
            $amount = in_array($type, AccountCategory::DEBIT_NORMAL, true) ? Money::sub($row['debits'], $row['credits']) : Money::sub($row['credits'], $row['debits']);
            $key = $account->category->id;
            $categories[$key] ??= ['category_id' => $key, 'name' => $account->category->name, 'accounts' => [], 'total' => Money::normalize(0)];
            $categories[$key]['accounts'][] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'amount' => $amount];
            $categories[$key]['total'] = Money::add($categories[$key]['total'], $amount);
            $total = Money::add($total, $amount);
        }

        return ['categories' => array_values($categories), 'total' => $total];
    }

    private function balanceOf(Account $account, ?Carbon $from, Carbon $to): string
    {
        foreach ($this->balancesBetween($from, $to) as $row) {
            if ($row['account']->id === $account->id) {
                return $this->closing($account, '0', $row['debits'], $row['credits']);
            }
        }

        return Money::normalize(0);
    }

    // ---- Helpers ---------------------------------------------------------------

    public function system(string $key): Account
    {
        return $this->accounts[$key] ??= Account::query()->with('category')->where('system_key', $key)->first()
            ?? throw new PostingException("The system account [{$key}] is missing. Run the default data sync.");
    }

    private function cash(mixed $accountId): Account
    {
        return is_numeric($accountId) ? Account::query()->with('category')->findOrFail((int) $accountId) : $this->system('cash_bank');
    }

    /**
     * Converts at the record's snapshotted rate and rounds to the base
     * currency's minor unit (§57.3 "Rounding and currency").
     */
    private function base(string $amount, string $rate): string
    {
        return $this->round(Money::mul($amount, $rate === '' ? '1' : $rate));
    }

    private function round(string $amount): string
    {
        return Money::round($amount, $this->currency());
    }

    public function currency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }

    public function today(): Carbon
    {
        return Carbon::now((string) ($this->settings->get('timezone') ?: 'UTC'))->startOfDay();
    }
}
