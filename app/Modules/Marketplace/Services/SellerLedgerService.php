<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Services;

use App\Modules\Accounting\Support\AccountingOutbox;
use App\Modules\Currency\Services\CurrencyService;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Models\SellerLedgerEntry;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * The seller ledger (spec §50.4), in the base currency. A sale entry per
 * seller line on confirmation, payable from completion + the hold; a
 * reversal for the refunded share of a line by every path that gives
 * money back. Reversals are capped at what is left of the sale, so two
 * causes for the same money (a cancellation, then its refund) never
 * reverse a line twice.
 */
final readonly class SellerLedgerService
{
    public function __construct(
        private SellerService $sellers,
        private TenantSettingsService $settings,
        private PlatformSettingsService $platformSettings,
        private CurrencyService $currencies,
        private AccountingOutbox $outbox,
    ) {}

    /**
     * The confirmation hook: seller gross = line subtotal − the seller-funded
     * discount, before tax (tenant-funded discounts are the tenant's cost).
     * Test orders write nothing.
     */
    public function recordOrderLines(Order $order): void
    {
        if ($order->is_test) {
            return;
        }

        $items = OrderItem::query()->where('order_id', $order->id)->whereNotNull('seller_id')->get();

        if ($items->isEmpty() || SellerLedgerEntry::query()->where('order_id', $order->id)->where('entry_type', SellerLedgerEntry::SALE)->exists()) {
            return;
        }

        $base = $this->currencies->baseCurrency();
        $toBase = (string) ($order->exchange_rate_used ?? '1');
        $rates = [];

        foreach ($items as $item) {
            $sellerId = (int) $item->seller_id;
            $rates[$sellerId] ??= $this->rateFor($sellerId);
            $lineSubtotal = bcmul((string) $item->unit_price, (string) $item->quantity, 10);
            $gross = Money::round(bcmul(Money::sub($lineSubtotal, (string) $item->seller_funded_discount_amount), $toBase, 12), $base);
            $commission = Money::round(bcdiv(bcmul($gross, $rates[$sellerId], 12), '100', 12), $base);

            $entry = new SellerLedgerEntry;
            $entry->forceFill([
                'seller_id' => $sellerId,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'entry_type' => SellerLedgerEntry::SALE,
                'gross_amount' => $gross,
                'commission_rate_applied' => $rates[$sellerId],
                'commission_amount' => $commission,
                'net_payable' => Money::sub($gross, $commission),
            ])->save();
        }

        // Sales revenue → commission revenue and payable to sellers (§50.5).
        $this->outbox->record('postSellerCommission', $order, $order->confirmed_at ?? now(), 'seller_commission:'.$order->id);
    }

    /**
     * The completion hook: sale entries become payable at completed_at +
     * seller_payout_hold_days, else the effective return window, so no
     * earning is paid while a return is still possible.
     */
    public function markAvailable(Order $order): void
    {
        $hold = $this->settings->get('seller_payout_hold_days')
            ?? $this->settings->get('return_window_days')
            ?? $this->platformSettings->get('default_return_window_days')
            ?? 0;

        SellerLedgerEntry::query()->where('order_id', $order->id)->where('entry_type', SellerLedgerEntry::SALE)->whereNull('available_at')
            ->update(['available_at' => ($order->completed_at ?? now())->copy()->addDays((int) $hold)]);
    }

    /**
     * The cancellation hook (a cancelled or voided confirmed order): what is
     * left of every seller line is reversed.
     */
    public function reverseForOrder(Order $order): void
    {
        $this->recordReversal($order, OrderItem::query()->where('order_id', $order->id)->whereNotNull('seller_id')->get()
            ->map(static fn (OrderItem $i): array => ['order_item_id' => $i->id, 'refunded_amount' => (string) $i->line_total])->all());
    }

    /**
     * A successful direct refund or lost chargeback (not a return's): its
     * amount is spread over the order's lines in proportion to line_total.
     */
    public function recordRefund(OrderPayment $row): void
    {
        if ($row->order_return_id !== null || $row->status !== OrderPayment::SUCCESSFUL) {
            return;
        }

        $order = Order::query()->find($row->order_id);

        if ($order === null || ! Money::isPositive((string) $order->total)) {
            return;
        }

        $share = bcdiv(Money::sub('0', (string) $row->amount_paid), (string) $order->total, 12);

        $this->recordReversal($row, OrderItem::query()->where('order_id', $order->id)->whereNotNull('seller_id')->get()
            ->map(static fn (OrderItem $i): array => ['order_item_id' => $i->id, 'refunded_amount' => bcmul((string) $i->line_total, $share, 12)])->all());
    }

    /**
     * A refunded return: each returned line's value, scaled by the share of
     * the returned value that was actually refunded.
     */
    public function recordReturnRefund(OrderReturn $return): void
    {
        $return->loadMissing('items.orderItem');
        $values = [];

        foreach ($return->items as $item) {
            $line = $item->orderItem;

            if ($line === null || $line->seller_id === null || ! Money::isPositive((string) $line->quantity)) {
                continue;
            }

            $values[$line->id] = bcdiv(bcmul((string) $line->line_total, (string) $item->quantity, 12), (string) $line->quantity, 12);
        }

        $returned = array_reduce($values, static fn (string $sum, string $v): string => bcadd($sum, $v, 12), '0');

        if ($values === [] || bccomp($returned, '0', 12) <= 0) {
            return;
        }

        $share = bcdiv((string) ($return->refund_amount ?? $returned), $returned, 12);
        $share = bccomp($share, '1', 12) > 0 ? '1' : $share;

        $this->recordReversal($return, array_map(static fn (int $id, string $value): array => ['order_item_id' => $id, 'refunded_amount' => bcmul($value, $share, 12)],
            array_keys($values), $values));
    }

    /**
     * §50.4: reversal = the sale entry × the refunded proportion of the line
     * (capped at what is left of it), rounded; payable at once so the next
     * payout nets it. One entry per line and cause.
     *
     * @param  list<array{order_item_id: int, refunded_amount: string}>  $lineAmounts  in the order currency, on line_total
     */
    public function recordReversal(Model $source, array $lineAmounts): void
    {
        $base = $this->currencies->baseCurrency();
        $totals = ['gross' => Money::normalize(0), 'commission' => Money::normalize(0), 'net' => Money::normalize(0)];
        $order = null;

        foreach ($lineAmounts as $line) {
            $sale = SellerLedgerEntry::query()->where('order_item_id', $line['order_item_id'])->where('entry_type', SellerLedgerEntry::SALE)->first();
            $item = OrderItem::query()->find($line['order_item_id']);

            if ($sale === null || $item === null || ! Money::isPositive((string) $item->line_total)
                || SellerLedgerEntry::query()->where('order_item_id', $sale->order_item_id)->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->exists()) {
                continue;
            }

            $share = bcdiv((string) $line['refunded_amount'], (string) $item->line_total, 12);
            $share = bccomp($share, '1', 12) > 0 ? '1' : $share;
            $reversed = SellerLedgerEntry::query()->where('order_item_id', $sale->order_item_id)->where('entry_type', SellerLedgerEntry::REVERSAL)
                ->selectRaw('COALESCE(SUM(gross_amount), 0) as gross, COALESCE(SUM(commission_amount), 0) as commission')->first();
            $leftGross = Money::add((string) $sale->gross_amount, (string) $reversed->gross);
            $leftCommission = Money::add((string) $sale->commission_amount, (string) $reversed->commission);
            $gross = Money::min(Money::round(bcmul((string) $sale->gross_amount, $share, 12), $base), $leftGross);
            $commission = Money::min(Money::round(bcmul((string) $sale->commission_amount, $share, 12), $base), $leftCommission);

            if (! Money::isPositive($gross)) {
                continue;
            }

            $entry = new SellerLedgerEntry;
            $entry->forceFill([
                'seller_id' => $sale->seller_id,
                'order_id' => $sale->order_id,
                'order_item_id' => $sale->order_item_id,
                'entry_type' => SellerLedgerEntry::REVERSAL,
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'gross_amount' => Money::sub('0', $gross),
                'commission_rate_applied' => $sale->commission_rate_applied,
                'commission_amount' => Money::sub('0', $commission),
                'net_payable' => Money::sub('0', Money::sub($gross, $commission)),
                'available_at' => now(),
            ])->save();

            $totals = ['gross' => Money::add($totals['gross'], $gross), 'commission' => Money::add($totals['commission'], $commission),
                'net' => Money::add($totals['net'], Money::sub($gross, $commission))];
            $order ??= Order::query()->find($sale->order_id);
        }

        if ($order !== null && Money::isPositive($totals['gross'])) {
            $this->outbox->record('postSellerReversal', $order, now(), 'seller_reversal:'.$source->getMorphClass().':'.$source->getKey(), $totals);
        }
    }

    /**
     * @return array{available: string, pending: string}
     */
    public function getUnpaidBalance(Seller $seller): array
    {
        $row = SellerLedgerEntry::query()->where('seller_id', $seller->id)->whereNull('seller_payout_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN available_at IS NOT NULL AND available_at <= ? THEN net_payable ELSE 0 END), 0) as available', [now()])
            ->selectRaw('COALESCE(SUM(CASE WHEN available_at IS NULL OR available_at > ? THEN net_payable ELSE 0 END), 0) as pending', [now()])
            ->first();

        return ['available' => Money::normalize((string) $row->available), 'pending' => Money::normalize((string) $row->pending)];
    }

    /**
     * @param  array{entry_type?: string, order_id?: int, unpaid?: bool, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, SellerLedgerEntry>
     */
    public function listEntries(Seller $seller, array $filters = []): LengthAwarePaginator
    {
        return SellerLedgerEntry::query()->with('order:id,order_number')->where('seller_id', $seller->id)
            ->when(isset($filters['entry_type']), static fn ($q) => $q->where('entry_type', $filters['entry_type']))
            ->when(isset($filters['order_id']), static fn ($q) => $q->where('order_id', $filters['order_id']))
            ->when(($filters['unpaid'] ?? false) === true, static fn ($q) => $q->whereNull('seller_payout_id'))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    private function rateFor(int $sellerId): string
    {
        $seller = Seller::withTrashed()->find($sellerId);

        return $seller === null ? Money::normalize((string) $this->settings->get('default_seller_commission_rate', '0')) : $this->sellers->getEffectiveCommissionRate($seller);
    }
}
