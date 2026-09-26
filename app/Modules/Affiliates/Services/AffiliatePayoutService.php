<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliatePayout;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Affiliate payouts (spec §21A.6). Money is sent outside the platform; a
 * payout records what was sent. The unique (affiliate, currency,
 * period_end) key makes a rerun of a period a no-op, and every transition
 * locks the payout and checks its state.
 */
final readonly class AffiliatePayoutService
{
    public function __construct(
        private PlatformSettingsService $settings,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * Creates the pending payouts for a period ending on $periodEnd (a
     * date in the platform timezone): per approved affiliate and currency,
     * the payable rows approved by the period end, when they reach the
     * currency's minimum. Anything smaller rolls forward.
     *
     * @return Collection<int, AffiliatePayout>
     */
    public function generate(CarbonInterface $periodEnd, ?PlatformUser $by = null): Collection
    {
        $timezone = $this->settings->timezone();
        $end = CarbonImmutable::parse($periodEnd->format('Y-m-d'), $timezone);
        $cutoff = $end->endOfDay()->utc();
        $thresholds = array_change_key_case((array) $this->settings->get('affiliate_minimum_payout', []), CASE_UPPER);
        $holdHours = (int) config('affiliates.payout_details_hold_hours', 72);
        $created = collect();

        Affiliate::query()->where('status', Affiliate::APPROVED)->orderBy('id')->chunkById(100, function ($affiliates) use ($end, $cutoff, $thresholds, $holdHours, $by, &$created): void {
            foreach ($affiliates as $affiliate) {
                $payouts = DB::connection('landlord')->transaction(function () use ($affiliate, $end, $cutoff, $thresholds, $holdHours, $by): array {
                    /** @var Affiliate $locked */
                    $locked = Affiliate::query()->whereKey($affiliate->id)->lockForUpdate()->firstOrFail();

                    if ($locked->status !== Affiliate::APPROVED || $locked->payout_method === null || $locked->payout_details === null || $locked->payout_details === []
                        || ($locked->payout_details_updated_at !== null && $locked->payout_details_updated_at->gt(now()->subHours($holdHours)))) {
                        return [];
                    }

                    // The rows are locked and summed together, so the payout
                    // amount is exactly the rows it contains.
                    $byCurrency = AffiliateCommission::query()
                        ->payable()
                        ->where('affiliate_id', $locked->id)
                        ->where('approved_at', '<=', $cutoff)
                        ->lockForUpdate()
                        ->get(['id', 'currency_code', 'amount'])
                        ->groupBy('currency_code');

                    $made = [];

                    foreach ($byCurrency as $currency => $rows) {
                        $total = $rows->reduce(static fn (string $sum, AffiliateCommission $c): string => Money::add($sum, (string) $c->amount), '0.0000');
                        $minimum = $thresholds[$currency] ?? null;

                        if ($minimum === null || ! Money::isPositive($total) || Money::cmp($total, Money::normalize((string) $minimum)) < 0) {
                            continue;
                        }

                        if (AffiliatePayout::query()->where('affiliate_id', $locked->id)->where('currency_code', $currency)->whereDate('period_end', $end->toDateString())->exists()) {
                            continue;
                        }

                        $ids = $rows->pluck('id');

                        /** @var AffiliatePayout $payout */
                        $payout = AffiliatePayout::query()->create([
                            'reference' => sprintf('AFP-%s-%06d-%s', $end->format('Ym'), $locked->id, $currency),
                            'affiliate_id' => $locked->id,
                            'currency_code' => $currency,
                            'amount' => $total,
                            'commission_count' => $ids->count(),
                            'period_start' => $end->startOfMonth()->toDateString(),
                            'period_end' => $end->toDateString(),
                            'status' => AffiliatePayout::PENDING,
                            'payout_method' => $locked->payout_method,
                            'payout_details_snapshot' => (array) $locked->payout_details,
                            'created_by' => $by?->id,
                        ]);

                        AffiliateCommission::query()->whereKey($ids)->update(['affiliate_payout_id' => $payout->id, 'updated_at' => now()]);
                        $made[] = $payout;
                    }

                    return $made;
                });

                foreach ($payouts as $payout) {
                    $created->push($payout);
                }
            }
        });

        if ($created->isNotEmpty()) {
            ActivityRecorder::landlord('affiliates', 'Affiliate payouts generated', null, ['period_end' => $end->toDateString(), 'count' => $created->count()], $by);
            $this->announce($created);
        }

        return $created;
    }

    public function markPaid(AffiliatePayout $payout, string $externalReference, PlatformUser $by): AffiliatePayout
    {
        $payout = $this->transition($payout, AffiliatePayout::PAID, static function (AffiliatePayout $locked) use ($externalReference, $by): void {
            if ($locked->status !== AffiliatePayout::PENDING) {
                throw ApiException::conflict('state_conflict', 'This payout is no longer pending.', ['status' => $locked->status]);
            }

            AffiliateCommission::query()->where('affiliate_payout_id', $locked->id)
                ->update(['status' => AffiliateCommission::PAID, 'paid_at' => now(), 'updated_at' => now()]);

            $locked->forceFill(['external_reference' => $externalReference, 'paid_at' => now(), 'paid_by' => $by->id]);
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate payout marked paid', $payout, ['external_reference' => $externalReference], $by);
        $this->notifyAffiliate('affiliate.payout_paid', $payout, ['reference' => $payout->reference]);

        return $payout;
    }

    /**
     * A pending payout the bank rejected, or (super-admin only) a paid one
     * whose transfer was returned: its rows become payable again.
     */
    public function markFailed(AffiliatePayout $payout, string $reason, PlatformUser $by): AffiliatePayout
    {
        $payout = $this->transition($payout, AffiliatePayout::FAILED, static function (AffiliatePayout $locked) use ($reason, $by): void {
            if ($locked->status === AffiliatePayout::PAID) {
                if (! PlatformUser::query()->whereKey($by->id)->withPlatformRole('super-admin')->exists()) {
                    throw ApiException::forbidden('forbidden', 'Only a super-admin can fail a paid payout.');
                }

                AffiliateCommission::query()->where('affiliate_payout_id', $locked->id)
                    ->update(['status' => AffiliateCommission::APPROVED, 'paid_at' => null, 'affiliate_payout_id' => null, 'updated_at' => now()]);
            } elseif ($locked->status === AffiliatePayout::PENDING) {
                AffiliateCommission::query()->where('affiliate_payout_id', $locked->id)->update(['affiliate_payout_id' => null, 'updated_at' => now()]);
            } else {
                throw ApiException::conflict('state_conflict', 'This payout cannot be marked failed.', ['status' => $locked->status]);
            }

            $locked->forceFill(['failed_at' => now(), 'failure_reason' => $reason]);
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate payout marked failed', $payout, ['reason' => $reason], $by);
        $this->notifyAffiliate('affiliate.payout_failed', $payout, ['reason' => $reason]);

        return $payout;
    }

    public function cancel(AffiliatePayout $payout, PlatformUser $by): AffiliatePayout
    {
        $payout = $this->transition($payout, AffiliatePayout::CANCELLED, static function (AffiliatePayout $locked): void {
            if ($locked->status !== AffiliatePayout::PENDING) {
                throw ApiException::conflict('state_conflict', 'Only a pending payout can be cancelled.', ['status' => $locked->status]);
            }

            AffiliateCommission::query()->where('affiliate_payout_id', $locked->id)->update(['affiliate_payout_id' => null, 'updated_at' => now()]);
            $locked->forceFill(['cancelled_at' => now()]);
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate payout cancelled', $payout, [], $by);

        return $payout;
    }

    /**
     * @param  callable(AffiliatePayout): void  $apply
     */
    private function transition(AffiliatePayout $payout, string $to, callable $apply): AffiliatePayout
    {
        return DB::connection('landlord')->transaction(static function () use ($payout, $to, $apply): AffiliatePayout {
            /** @var AffiliatePayout $locked */
            $locked = AffiliatePayout::query()->whereKey($payout->id)->lockForUpdate()->firstOrFail();

            $apply($locked);
            $locked->forceFill(['status' => $to])->save();

            return $locked;
        });
    }

    /**
     * @param  Collection<int, AffiliatePayout>  $payouts
     */
    private function announce(Collection $payouts): void
    {
        $admins = PlatformUser::query()->withPlatformRole('billing-admin')->where('is_active', true)->get();

        if ($admins->isEmpty()) {
            return;
        }

        $this->notifications->dispatch('affiliate.payouts_ready', $admins, [
            'count' => (string) $payouts->count(),
            'amount' => $payouts->groupBy('currency_code')
                ->map(static fn (Collection $group, string $currency): string => Money::format(
                    $group->reduce(static fn (string $sum, AffiliatePayout $p): string => Money::add($sum, (string) $p->amount), '0'),
                    $currency,
                ))
                ->implode(', '),
        ]);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function notifyAffiliate(string $key, AffiliatePayout $payout, array $extra = []): void
    {
        $affiliate = Affiliate::query()->find($payout->affiliate_id);

        if ($affiliate !== null) {
            $this->notifications->dispatch($key, $affiliate, [
                'name' => $affiliate->name,
                'amount' => Money::format(Money::normalize((string) $payout->amount), $payout->currency_code),
                ...$extra,
            ]);
        }
    }
}
