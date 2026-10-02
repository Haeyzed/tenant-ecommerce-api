<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliatePayout;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Modules\Affiliates\Services\AffiliatePayoutService;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local demo data for the affiliate screens and their E2E tests: an
 * approved affiliate (code E2EAFF) with payout details, a pending
 * applicant, and referrals on existing stores that already have a paid
 * charge, one per state worth seeing (payable, under review, on hold),
 * plus a payout generated through the real service. Only inserts; --remove
 * deletes exactly these rows. Refuses to run outside local and testing.
 */
#[Signature('affiliates:demo {--remove : Delete the demo affiliates and everything attached to them}')]
#[Description('Create (or remove) local demo affiliates, referrals, commissions and a payout')]
final class SeedDemoAffiliates extends Command
{
    private const array EMAILS = ['e2e-affiliate@affiliates.test', 'e2e-applicant@affiliates.test'];

    public function handle(AffiliatePayoutService $payouts): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Demo affiliates are for local development only.');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $this->remove();

            return self::SUCCESS;
        }

        if (Affiliate::query()->whereIn('email', self::EMAILS)->exists()) {
            $this->info('Demo affiliates already exist. Run with --remove first to recreate them.');

            return self::SUCCESS;
        }

        $affiliate = $this->affiliate('E2E Affiliate', self::EMAILS[0], Affiliate::APPROVED, 'E2EAFF');
        $affiliate->forceFill([
            'approved_at' => now()->subDays(40),
            'payout_method' => 'bank_transfer',
            'payout_details' => ['account_name' => 'E2E Affiliate', 'account_number' => '0123456789', 'bank_name' => 'Demo Bank'],
            'payout_details_updated_at' => now()->subDays(30),
        ])->save();
        $this->affiliate('E2E Applicant', self::EMAILS[1], Affiliate::PENDING, null);

        // Stores with a paid charge and no referral yet; one commission per referral.
        $charges = PaymentTransaction::query()
            ->where('type', PaymentTransaction::CHARGE)
            ->where('status', PaymentTransaction::SUCCESSFUL)
            ->whereNotNull('subscription_id')
            ->whereNotIn('tenant_id', AffiliateReferral::query()->select('tenant_id'))
            ->orderBy('id')
            ->get()
            ->unique('tenant_id')
            ->take(3)
            ->values();

        $states = ['payable', 'review', 'hold'];

        foreach ($charges as $i => $charge) {
            $this->referralWithCommission($affiliate, $charge, $states[$i]);
            $this->line("Referral and {$states[$i]} commission on tenant {$charge->tenant_id}");
        }

        if ($charges->isEmpty()) {
            $this->warn('No store has a paid charge, so no referrals or commissions were created.');
        }

        $created = $payouts->generate(CarbonImmutable::today());
        $this->info("Demo affiliates created; {$created->count()} payout(s) generated.");

        return self::SUCCESS;
    }

    private function affiliate(string $name, string $email, string $status, ?string $code): Affiliate
    {
        $affiliate = new Affiliate(['name' => $name, 'email' => $email, 'password' => Str::password(24), 'promotion_methods' => 'Demo data for local testing']);
        $affiliate->forceFill([
            'public_id' => (string) Str::uuid(),
            'status' => $status,
            'referral_code' => $code,
            'email_verified_at' => now()->subDays(45),
            'created_at' => now()->subDays(45),
        ])->save();

        return $affiliate;
    }

    /**
     * payable: approved and past its hold; review: pending, hold passed,
     * flagged; hold: pending and still within its hold.
     */
    private function referralWithCommission(Affiliate $affiliate, PaymentTransaction $charge, string $state): void
    {
        $paidAt = CarbonImmutable::parse($charge->paid_at ?? $charge->created_at);

        $referral = AffiliateReferral::query()->create([
            'affiliate_id' => $affiliate->id,
            'tenant_id' => $charge->tenant_id,
            'source' => 'link',
            'status' => AffiliateReferral::CONVERTED,
            'attributed_at' => $paidAt->subDays(2),
            'conversion_deadline' => $paidAt->addDays(90),
        ]);
        $referral->forceFill(['converted_at' => $paidAt])->save();

        if ($state === 'review') {
            $referral->addFlag('shared_payment_method', 'Demo flag: the payment method also paid for another referred store.');
            $referral->save();
        }

        $base = Money::normalize((string) $charge->amount);
        $commission = AffiliateCommission::query()->create([
            'affiliate_id' => $affiliate->id,
            'affiliate_referral_id' => $referral->id,
            'tenant_id' => $charge->tenant_id,
            'subscription_id' => $charge->subscription_id,
            'type' => AffiliateCommission::COMMISSION,
            'payment_transaction_id' => $charge->id,
            'base_amount' => $base,
            'currency_code' => $charge->currency_code,
            'commission_rate_applied' => '20.0000',
            'amount' => bcmul($base, '0.2', 4),
            'status' => $state === 'payable' ? AffiliateCommission::APPROVED : AffiliateCommission::PENDING,
            'requires_review' => $state === 'review',
            'hold_until' => $state === 'hold' ? now()->addDays(20) : now()->subDays(5),
        ]);

        if ($state === 'payable') {
            $commission->forceFill(['approved_at' => now()->subDays(2)])->save();
        }
    }

    private function remove(): void
    {
        $ids = Affiliate::query()->whereIn('email', self::EMAILS)->pluck('id');

        DB::connection('landlord')->transaction(static function () use ($ids): void {
            AffiliateCommission::query()->whereIn('affiliate_id', $ids)->whereNotNull('reverses_commission_id')->delete();
            AffiliateCommission::query()->whereIn('affiliate_id', $ids)->delete();
            AffiliatePayout::query()->whereIn('affiliate_id', $ids)->delete();
            AffiliateReferral::query()->whereIn('affiliate_id', $ids)->delete();
            DB::connection('landlord')->table('affiliate_clicks')->whereIn('affiliate_id', $ids)->delete();
            Affiliate::query()->whereIn('id', $ids)->each(static function (Affiliate $affiliate): void {
                $affiliate->tokens()->delete();
                $affiliate->delete();
            });
        });

        $this->info("Removed {$ids->count()} demo affiliate(s) and their records.");
    }
}
