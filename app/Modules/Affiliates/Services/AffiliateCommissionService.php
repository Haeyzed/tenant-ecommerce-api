<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliatePayout;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Affiliate commissions (spec §21A.5): one per referred tenant, on its
 * first paid live charge only. The billing hooks run inside the billing
 * transaction that makes the charge or reversal successful, so a
 * commission is never lost or duplicated between two jobs; unique keys on
 * (payment_transaction_id, type) and on the referral make retries no-ops.
 */
final readonly class AffiliateCommissionService
{
    public function __construct(
        private AffiliateService $affiliates,
        private AffiliateFraudService $fraud,
        private PlatformSettingsService $settings,
        private NotificationDispatchService $notifications,
    ) {}

    /**
     * Billing hook: the tenant's first paid live charge succeeded. Runs
     * whatever the programme switch says (it settles an obligation).
     */
    public function recordQualifyingPayment(PaymentTransaction $charge): ?AffiliateCommission
    {
        if (! $charge->is_first_paid_charge || $charge->mode !== 'live' || $charge->type !== PaymentTransaction::CHARGE
            || $charge->status !== PaymentTransaction::SUCCESSFUL || ! Money::isPositive((string) $charge->amount)) {
            return null;
        }

        /** @var AffiliateReferral|null $referral */
        $referral = AffiliateReferral::query()->with('affiliate')->where('tenant_id', $charge->tenant_id)->lockForUpdate()->first();

        if ($referral === null || $referral->status !== AffiliateReferral::REGISTERED) {
            return null;
        }

        $paidAt = CarbonImmutable::parse($charge->paid_at ?? now());

        if ($referral->conversion_deadline !== null && $paidAt->gt($referral->conversion_deadline)) {
            $referral->forceFill(['status' => AffiliateReferral::INELIGIBLE, 'ineligible_reason' => 'conversion_window_expired'])->save();

            return null;
        }

        $affiliate = $referral->affiliate;

        if (! $affiliate->isApproved()) {
            $referral->forceFill(['status' => AffiliateReferral::INELIGIBLE, 'ineligible_reason' => 'affiliate_not_active'])->save();

            return null;
        }

        $rate = $this->affiliates->effectiveRate($affiliate);
        $base = Money::normalize((string) $charge->amount);

        /** @var AffiliateCommission $commission */
        $commission = AffiliateCommission::query()->create([
            'affiliate_id' => $affiliate->id,
            'affiliate_referral_id' => $referral->id,
            'tenant_id' => $charge->tenant_id,
            'subscription_id' => $charge->subscription_id,
            'type' => AffiliateCommission::COMMISSION,
            'payment_transaction_id' => $charge->id,
            'base_amount' => $base,
            'currency_code' => $charge->currency_code,
            'commission_rate_applied' => $rate,
            'amount' => Money::round(Money::div(Money::mul($base, $rate), '100'), $charge->currency_code),
            'status' => AffiliateCommission::PENDING,
            'requires_review' => $referral->requires_review,
            'hold_until' => $paidAt->addDays((int) $this->settings->get('affiliate_commission_hold_days', 30)),
        ]);

        $referral->forceFill(['status' => AffiliateReferral::CONVERTED, 'converted_at' => $paidAt])->save();

        $this->fraud->evaluateCommission($commission, $referral, $charge);

        $this->notifications->dispatch('affiliate.commission_created', $affiliate, [
            'name' => $affiliate->name,
            'amount' => Money::format((string) $commission->amount, $commission->currency_code),
            'hold_until' => $commission->hold_until->toFormattedDateString(),
        ]);

        return $commission;
    }

    /**
     * Billing hook: a refund or chargeback of a charge became successful.
     * Only the qualifying charge's commission is affected (§21A.5).
     */
    public function handlePaymentReversal(PaymentTransaction $reversal): void
    {
        if (! in_array($reversal->type, [PaymentTransaction::REFUND, PaymentTransaction::CHARGEBACK], true)
            || $reversal->status !== PaymentTransaction::SUCCESSFUL || $reversal->refund_of_payment_transaction_id === null) {
            return;
        }

        /** @var AffiliateCommission|null $commission */
        $commission = AffiliateCommission::query()
            ->where('payment_transaction_id', $reversal->refund_of_payment_transaction_id)
            ->where('type', AffiliateCommission::COMMISSION)
            ->lockForUpdate()
            ->first();

        if ($commission === null || in_array($commission->status, [AffiliateCommission::REJECTED, AffiliateCommission::REVERSED], true)) {
            return;
        }

        $charge = PaymentTransaction::query()->findOrFail($reversal->refund_of_payment_transaction_id);
        $chargeAmount = Money::normalize((string) $charge->amount);
        $reason = $reversal->type === PaymentTransaction::CHARGEBACK ? 'chargeback' : 'refund';

        $refundedTotal = Money::normalize((string) PaymentTransaction::query()
            ->where('refund_of_payment_transaction_id', $charge->id)
            ->whereIn('type', [PaymentTransaction::REFUND, PaymentTransaction::CHARGEBACK])
            ->where('status', PaymentTransaction::SUCCESSFUL)
            ->sum(DB::raw('ABS(amount)')));
        $fullyRefunded = Money::cmp($refundedTotal, $chargeAmount) >= 0;

        if ($commission->status === AffiliateCommission::PAID) {
            $this->clawBack($commission, $reversal, $chargeAmount);
        } else {
            $this->reduceUnpaid($commission, $refundedTotal, $chargeAmount, $reason);
        }

        if ($fullyRefunded) {
            // No later charge can qualify: refund-and-repay is not a new sale.
            AffiliateReferral::query()->whereKey($commission->affiliate_referral_id)
                ->update(['status' => AffiliateReferral::INELIGIBLE, 'ineligible_reason' => 'first_payment_refunded', 'updated_at' => now()]);
        }

        $affiliate = Affiliate::query()->find($commission->affiliate_id);

        if ($affiliate !== null) {
            $this->notifications->dispatch('affiliate.commission_reversed', $affiliate, [
                'name' => $affiliate->name,
                'amount' => Money::format(Money::normalize((string) ($commission->original_amount ?? $commission->amount)), $commission->currency_code),
            ]);
        }
    }

    /**
     * Billing hook: a dispute was opened on the qualifying charge; approval
     * and payout wait until it resolves.
     */
    public function handleDisputeOpened(PaymentTransaction $charge): void
    {
        AffiliateCommission::query()
            ->where('payment_transaction_id', $charge->id)
            ->where('type', AffiliateCommission::COMMISSION)
            ->whereIn('status', [AffiliateCommission::PENDING, AffiliateCommission::APPROVED])
            ->update(['requires_review' => true, 'updated_at' => now()]);
    }

    /**
     * Billing hook: a dispute was won. The review hold lifts unless the
     * referral itself is flagged.
     */
    public function handleDisputeWon(PaymentTransaction $charge): void
    {
        $commission = AffiliateCommission::query()
            ->with('referral')
            ->where('payment_transaction_id', $charge->id)
            ->where('type', AffiliateCommission::COMMISSION)
            ->whereIn('status', [AffiliateCommission::PENDING, AffiliateCommission::APPROVED])
            ->first();

        if ($commission !== null && ! $commission->referral->requires_review) {
            $commission->forceFill(['requires_review' => false])->save();
        }
    }

    /**
     * Manual approval: only after the hold, and a flagged commission only
     * with a note that clears the review.
     */
    public function approve(AffiliateCommission $commission, PlatformUser $by, ?string $note = null): AffiliateCommission
    {
        $commission = $this->transition($commission, [AffiliateCommission::PENDING], AffiliateCommission::APPROVED, static function (AffiliateCommission $locked) use ($by, $note): void {
            if ($locked->hold_until->isFuture()) {
                throw ApiException::unprocessable('commission_on_hold', 'This commission is still in its hold period.', ['hold_until' => $locked->hold_until->toIso8601String()]);
            }

            if ($locked->requires_review && blank($note)) {
                throw ApiException::unprocessable('review_note_required', 'This commission is under review; add a note to approve it.');
            }

            $locked->forceFill(['requires_review' => false, 'approved_at' => now(), 'approved_by' => $by->id]);
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate commission approved', $commission, ['note' => $note], $by);
        $this->notifyAffiliate('affiliate.commission_approved', $commission);

        return $commission;
    }

    public function reject(AffiliateCommission $commission, string $reason, PlatformUser $by): AffiliateCommission
    {
        $commission = $this->transition($commission, [AffiliateCommission::PENDING, AffiliateCommission::APPROVED], AffiliateCommission::REJECTED, function (AffiliateCommission $locked) use ($reason): void {
            $this->detachFromPendingPayout($locked);
            $locked->forceFill(['rejected_at' => now(), 'rejection_reason' => $reason]);
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate commission rejected', $commission, ['reason' => $reason], $by);
        $this->notifyAffiliate('affiliate.commission_rejected', $commission, ['reason' => $reason]);

        return $commission;
    }

    public function reverse(AffiliateCommission $commission, string $reason, PlatformUser $by): AffiliateCommission
    {
        $commission = $this->transition($commission, [AffiliateCommission::PENDING, AffiliateCommission::APPROVED], AffiliateCommission::REVERSED, function (AffiliateCommission $locked): void {
            $this->detachFromPendingPayout($locked);
            $locked->forceFill(['reversed_at' => now(), 'reversal_reason' => 'manual']);
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate commission reversed', $commission, ['reason' => $reason], $by);
        $this->notifyAffiliate('affiliate.commission_reversed', $commission);

        return $commission;
    }

    /**
     * A manager rejects a referral (for example as fraud): terminal, and
     * its unpaid commissions are rejected with it. Paid ones are left to
     * the refund and clawback rules.
     */
    public function rejectReferral(AffiliateReferral $referral, string $reason, PlatformUser $by): AffiliateReferral
    {
        $referral = DB::connection('landlord')->transaction(function () use ($referral, $reason, $by): AffiliateReferral {
            /** @var AffiliateReferral $locked */
            $locked = AffiliateReferral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === AffiliateReferral::REJECTED) {
                throw ApiException::invalidTransition($locked->status, AffiliateReferral::REJECTED);
            }

            $locked->forceFill(['status' => AffiliateReferral::REJECTED, 'reviewed_by' => $by->id])->save();

            AffiliateCommission::query()
                ->where('affiliate_referral_id', $locked->id)
                ->where('type', AffiliateCommission::COMMISSION)
                ->whereIn('status', [AffiliateCommission::PENDING, AffiliateCommission::APPROVED])
                ->lockForUpdate()
                ->get()
                ->each(function (AffiliateCommission $commission) use ($reason): void {
                    $this->detachFromPendingPayout($commission);
                    $commission->forceFill(['status' => AffiliateCommission::REJECTED, 'rejected_at' => now(), 'rejection_reason' => $reason])->save();
                });

            return $locked;
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate referral rejected', $referral, ['reason' => $reason], $by);

        return $referral;
    }

    /**
     * Automatic mode: approves every eligible commission of an approved
     * affiliate. Returns the number approved.
     */
    public function approveEligible(): int
    {
        $approved = 0;

        AffiliateCommission::query()
            ->eligible()
            ->where('type', AffiliateCommission::COMMISSION)
            ->whereHas('affiliate', static fn ($q) => $q->where('status', Affiliate::APPROVED))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$approved): void {
                foreach ($rows as $row) {
                    $done = DB::connection('landlord')->transaction(static function () use ($row): bool {
                        /** @var AffiliateCommission $locked */
                        $locked = AffiliateCommission::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

                        if ($locked->status !== AffiliateCommission::PENDING || $locked->requires_review || $locked->hold_until->isFuture()) {
                            return false;
                        }

                        $locked->forceFill(['status' => AffiliateCommission::APPROVED, 'approved_at' => now(), 'approved_by' => null])->save();

                        return true;
                    });

                    if ($done) {
                        $approved++;
                        $this->notifyAffiliate('affiliate.commission_approved', $row->refresh());
                    }
                }
            });

        return $approved;
    }

    /**
     * Balances per currency (§21A.8): pending, payable (approved and not
     * in a payout, clawbacks included), paid and reversed.
     *
     * @return array<string, array{pending: string, payable: string, paid: string, reversed: string}>
     */
    public function balances(Affiliate $affiliate): array
    {
        $rows = AffiliateCommission::query()
            ->where('affiliate_id', $affiliate->id)
            ->groupBy('currency_code')
            ->selectRaw("currency_code,
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' AND affiliate_payout_id IS NULL THEN amount ELSE 0 END) as payable,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid,
                SUM(CASE WHEN status = 'reversed' THEN COALESCE(original_amount, amount) WHEN type = 'clawback' THEN -amount ELSE 0 END) as reversed")
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            $balances[(string) $row->currency_code] = [
                'pending' => Money::normalize((string) $row->pending),
                'payable' => Money::normalize((string) $row->payable),
                'paid' => Money::normalize((string) $row->paid),
                'reversed' => Money::normalize((string) $row->reversed),
            ];
        }

        return $balances;
    }

    /**
     * A refund of a paid commission's charge: an approved, negative row
     * netted against the next payout, never more than what was paid.
     */
    private function clawBack(AffiliateCommission $commission, PaymentTransaction $reversal, string $chargeAmount): void
    {
        if (AffiliateCommission::query()->where('payment_transaction_id', $reversal->id)->where('type', AffiliateCommission::CLAWBACK)->exists()) {
            return;
        }

        $share = Money::div(ltrim(Money::normalize((string) $reversal->amount), '-'), $chargeAmount);
        $already = ltrim(Money::normalize((string) AffiliateCommission::query()->where('reverses_commission_id', $commission->id)->sum('amount')), '-');
        $remaining = Money::sub((string) $commission->amount, $already);
        $claw = Money::min(Money::round(Money::mul((string) $commission->amount, Money::min($share, '1')), $commission->currency_code), $remaining);

        if (! Money::isPositive($claw)) {
            return;
        }

        AffiliateCommission::query()->create([
            'affiliate_id' => $commission->affiliate_id,
            'affiliate_referral_id' => $commission->affiliate_referral_id,
            'tenant_id' => $commission->tenant_id,
            'subscription_id' => $commission->subscription_id,
            'type' => AffiliateCommission::CLAWBACK,
            'reverses_commission_id' => $commission->id,
            'payment_transaction_id' => $reversal->id,
            'base_amount' => Money::normalize((string) $reversal->amount),
            'currency_code' => $commission->currency_code,
            'commission_rate_applied' => $commission->commission_rate_applied,
            'amount' => Money::sub('0', $claw),
            'status' => AffiliateCommission::APPROVED,
            'requires_review' => false,
            'hold_until' => now(),
        ])->forceFill(['approved_at' => now()])->save();
    }

    /**
     * Pending or approved: reduced in proportion to everything refunded so
     * far, reversed when nothing remains.
     */
    private function reduceUnpaid(AffiliateCommission $commission, string $refundedTotal, string $chargeAmount, string $reason): void
    {
        $original = Money::normalize((string) ($commission->original_amount ?? $commission->amount));
        $share = Money::min(Money::div($refundedTotal, $chargeAmount), '1');
        $remaining = Money::round(Money::mul($original, Money::sub('1', $share)), $commission->currency_code);

        $this->detachFromPendingPayout($commission);

        if (! Money::isPositive($remaining)) {
            $commission->forceFill([
                'original_amount' => $original,
                'status' => AffiliateCommission::REVERSED,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();

            return;
        }

        $commission->forceFill(['original_amount' => $original, 'amount' => $remaining])->save();
    }

    /**
     * A commission leaving an unpaid payout takes its amount with it; an
     * emptied or no-longer-positive payout is cancelled.
     */
    private function detachFromPendingPayout(AffiliateCommission $commission): void
    {
        if ($commission->affiliate_payout_id === null) {
            return;
        }

        /** @var AffiliatePayout|null $payout */
        $payout = AffiliatePayout::query()->whereKey($commission->affiliate_payout_id)->lockForUpdate()->first();

        if ($payout === null || $payout->status !== AffiliatePayout::PENDING) {
            return;
        }

        $commission->forceFill(['affiliate_payout_id' => null])->save();

        $rest = AffiliateCommission::query()->where('affiliate_payout_id', $payout->id);
        $amount = Money::normalize((string) (clone $rest)->sum('amount'));
        $count = (clone $rest)->count();

        if ($count === 0 || ! Money::isPositive($amount)) {
            $rest->update(['affiliate_payout_id' => null]);
            $payout->forceFill(['status' => AffiliatePayout::CANCELLED, 'cancelled_at' => now(), 'amount' => $amount, 'commission_count' => 0])->save();

            return;
        }

        $payout->forceFill(['amount' => $amount, 'commission_count' => $count])->save();
    }

    /**
     * @param  list<string>  $from
     * @param  callable(AffiliateCommission): void  $apply
     */
    private function transition(AffiliateCommission $commission, array $from, string $to, callable $apply): AffiliateCommission
    {
        return DB::connection('landlord')->transaction(static function () use ($commission, $from, $to, $apply): AffiliateCommission {
            /** @var AffiliateCommission $locked */
            $locked = AffiliateCommission::query()->whereKey($commission->id)->lockForUpdate()->firstOrFail();

            if ($locked->type !== AffiliateCommission::COMMISSION || ! in_array($locked->status, $from, true)) {
                throw ApiException::invalidTransition($locked->status, $to);
            }

            $apply($locked);
            $locked->forceFill(['status' => $to])->save();

            return $locked;
        });
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function notifyAffiliate(string $key, AffiliateCommission $commission, array $extra = []): void
    {
        $affiliate = Affiliate::query()->find($commission->affiliate_id);

        if ($affiliate !== null) {
            $this->notifications->dispatch($key, $affiliate, [
                'name' => $affiliate->name,
                'amount' => Money::format(Money::normalize((string) ($commission->original_amount ?? $commission->amount)), $commission->currency_code),
                ...$extra,
            ]);
        }
    }
}
