<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Services;

use App\Modules\Accounting\Support\AccountingOutbox;
use App\Modules\Marketplace\Models\Seller;
use App\Modules\Marketplace\Models\SellerLedgerEntry;
use App\Modules\Marketplace\Models\SellerPayout;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Seller payouts (spec §50.5): the unassigned entries (sales and
 * reversals) available within a period, collected under the seller row
 * lock so no entry is ever paid twice. Paid outside the platform.
 */
final readonly class SellerPayoutService
{
    public function __construct(
        private AccountingOutbox $outbox,
        private TenantSettingsService $settings,
    ) {}

    /**
     * A preview, nothing persisted.
     *
     * @return array{period_start: string, period_end: string, entries: int, gross_amount: string, commission_amount: string, net_payable: string}
     */
    public function calculatePayout(Seller $seller, string $from, string $to): array
    {
        [$start, $end] = $this->period($from, $to);
        $row = $this->entries($seller, $start, $end)
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(gross_amount), 0) as gross, COALESCE(SUM(commission_amount), 0) as commission, COALESCE(SUM(net_payable), 0) as net')
            ->toBase()->first();

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'entries' => (int) $row->entries,
            'gross_amount' => Money::normalize((string) $row->gross),
            'commission_amount' => Money::normalize((string) $row->commission),
            'net_payable' => Money::normalize((string) $row->net),
        ];
    }

    /**
     * Refused when the net would be zero or less: the entries stay
     * unassigned and are netted against later earnings.
     */
    public function generatePayout(Seller $seller, string $from, string $to, User $by): SellerPayout
    {
        [$start, $end] = $this->period($from, $to);

        return DB::connection('tenant')->transaction(function () use ($seller, $start, $end, $by): SellerPayout {
            Seller::withTrashed()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $entries = $this->entries($seller, $start, $end)->lockForUpdate()->get(['id', 'gross_amount', 'commission_amount', 'net_payable']);
            $sum = static fn (string $column): string => $entries->reduce(static fn (string $s, SellerLedgerEntry $e): string => Money::add($s, (string) $e->{$column}), Money::normalize(0));
            $net = $sum('net_payable');

            if (! Money::isPositive($net)) {
                throw ApiException::unprocessable('payout_not_positive', 'Nothing is payable to this seller for the period.', ['net_payable' => $net, 'entries' => $entries->count()]);
            }

            $payout = new SellerPayout;
            $payout->forceFill([
                'seller_id' => $seller->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'gross_amount' => $sum('gross_amount'),
                'commission_amount' => $sum('commission_amount'),
                'net_payable' => $net,
                'status' => SellerPayout::PENDING,
                'created_by' => $by->id,
            ])->save();

            SellerLedgerEntry::query()->whereKey($entries->pluck('id'))->update(['seller_payout_id' => $payout->id]);

            return $payout;
        });
    }

    /**
     * Once paid outside the platform: Dr Accounts Payable – Sellers,
     * Cr Cash/Bank when accounting is on (§50.5).
     */
    public function markPaid(SellerPayout $payout, ?string $reference = null, ?string $notes = null): SellerPayout
    {
        Validator::make(['reference' => $reference, 'notes' => $notes], [
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return DB::connection('tenant')->transaction(function () use ($payout, $reference, $notes): SellerPayout {
            /** @var SellerPayout $locked */
            $locked = SellerPayout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($locked->status !== SellerPayout::PENDING) {
                throw ApiException::invalidTransition($locked->status, SellerPayout::PAID);
            }

            $locked->forceFill(['status' => SellerPayout::PAID, 'paid_at' => now(), 'reference' => $reference, 'notes' => $notes])->save();
            $this->outbox->record('postSellerPayout', $locked, now(), 'seller_payout:'.$locked->id);

            return $locked;
        });
    }

    /**
     * @return Collection<int, SellerPayout>
     */
    public function listPayouts(Seller $seller): Collection
    {
        return SellerPayout::query()->where('seller_id', $seller->id)->orderByDesc('period_end')->orderByDesc('id')->get();
    }

    /**
     * @return Builder<SellerLedgerEntry>
     */
    private function entries(Seller $seller, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return SellerLedgerEntry::query()->where('seller_id', $seller->id)->whereNull('seller_payout_id')
            ->whereNotNull('available_at')->whereBetween('available_at', [$start, $end]);
    }

    /**
     * Local calendar days (the store timezone), never ending in the future.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(string $from, string $to): array
    {
        Validator::make(['from' => $from, 'to' => $to], [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ])->validate();

        $timezone = (string) ($this->settings->get('timezone') ?: 'UTC');
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $timezone)->endOfDay()->utc();

        return [$start, $end->gt(now()) ? CarbonImmutable::now() : $end];
    }
}
