<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Services;

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Modules\Affiliates\Support\AffiliateIdentity;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Tenancy\Models\TenantRegistration;
use App\Shared\Activity\ActivityRecorder;
use App\Shared\Activity\LandlordActivity;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Practical, explainable fraud controls (spec §21A.7): hard rules make a
 * referral ineligible; review flags block automatic approval and payout
 * until a manager clears or rejects them.
 */
final readonly class AffiliateFraudService
{
    public function __construct(private NotificationDispatchService $notifications) {}

    /**
     * Runs at attribution. Hard rules set the referral ineligible; flags
     * put it under review. The caller saves the referral and announces
     * the returned flags.
     *
     * @return list<string> the flags raised
     */
    public function evaluateReferral(AffiliateReferral $referral, Affiliate $affiliate, ?TenantRegistration $registration, string $ownerEmail): array
    {
        $owner = AffiliateIdentity::normalizeEmail($ownerEmail);

        if ($owner === AffiliateIdentity::normalizeEmail($affiliate->email)) {
            $this->ineligible($referral, 'self_referral');

            return [];
        }

        if ($this->isExistingCustomer($owner, $referral->tenant_id)) {
            $this->ineligible($referral, 'existing_customer');

            return [];
        }

        $flagged = [];
        $ipHash = $registration?->ip_address !== null ? AffiliateIdentity::ipHash($registration->ip_address) : null;

        if ($ipHash !== null && in_array($ipHash, $this->affiliateIpHashes($affiliate), true)) {
            $flagged[] = $referral->addFlag('affiliate_ip_match', 'The registration came from an IP address the affiliate logged in from.') ? 'affiliate_ip_match' : null;
        }

        $domain = AffiliateIdentity::domain($ownerEmail);

        if ($domain !== '' && $domain === AffiliateIdentity::domain($affiliate->email)
            && ! in_array($domain, (array) config('affiliates.public_mail_domains'), true)) {
            $flagged[] = $referral->addFlag('email_domain_match', "The owner email shares the affiliate's domain {$domain}.") ? 'email_domain_match' : null;
        }

        $since = now()->subDay();
        $byAffiliate = AffiliateReferral::query()->where('affiliate_id', $affiliate->id)->where('attributed_at', '>=', $since)->count();
        $byIp = $registration?->ip_address === null ? 0 : AffiliateReferral::query()
            ->join('tenant_registrations', 'tenant_registrations.id', '=', 'affiliate_referrals.tenant_registration_id')
            ->where('tenant_registrations.ip_address', $registration->ip_address)
            ->where('affiliate_referrals.attributed_at', '>=', $since)
            ->count();

        // The referral being evaluated is not saved yet, so it adds one.
        if ($byAffiliate + 1 > (int) config('affiliates.velocity.per_affiliate_24h', 10) || $byIp + 1 > (int) config('affiliates.velocity.per_ip_24h', 3)) {
            $flagged[] = $referral->addFlag('velocity', 'Unusually many referrals in 24 hours for this affiliate or IP address.') ? 'velocity' : null;
        }

        return array_values(array_filter($flagged));
    }

    /**
     * Runs when a commission is created: a payment method shared with
     * another referred tenant, or an affiliate whose recent conversions
     * are often refunded, puts the commission under review.
     */
    public function evaluateCommission(AffiliateCommission $commission, AffiliateReferral $referral, PaymentTransaction $charge): void
    {
        $fingerprint = $charge->meta['payment_method_fingerprint'] ?? null;
        $flags = [];

        if (is_string($fingerprint) && $fingerprint !== '') {
            $shared = AffiliateCommission::query()
                ->join('payment_transactions', 'payment_transactions.id', '=', 'affiliate_commissions.payment_transaction_id')
                ->where('affiliate_commissions.affiliate_id', $commission->affiliate_id)
                ->where('affiliate_commissions.type', AffiliateCommission::COMMISSION)
                ->where('affiliate_commissions.id', '!=', $commission->id)
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payment_transactions.meta, '$.payment_method_fingerprint')) = ?", [$fingerprint])
                ->exists();

            if ($shared && $referral->addFlag('shared_payment_method', 'The payment method also paid for another tenant referred by this affiliate.')) {
                $flags[] = 'shared_payment_method';
            }
        }

        if ($this->hasRapidRefunds($commission->affiliate_id)) {
            $flags[] = 'rapid_refund';
        }

        if ($flags !== []) {
            $commission->forceFill(['requires_review' => true])->save();
            $referral->forceFill(['requires_review' => true])->save();
            $this->announce($referral->affiliate, $referral, $flags);
        }
    }

    /**
     * More than the allowed share of the affiliate's conversions in the
     * window were refunded (an affiliate-level signal, §21A.7).
     */
    public function hasRapidRefunds(int $affiliateId): bool
    {
        $rows = AffiliateCommission::query()
            ->where('affiliate_id', $affiliateId)
            ->where('type', AffiliateCommission::COMMISSION)
            ->where('created_at', '>=', now()->subDays((int) config('affiliates.rapid_refund.window_days', 90)))
            ->selectRaw("COUNT(*) as conversions, SUM(CASE WHEN status = 'reversed' AND reversal_reason IN ('refund', 'chargeback') THEN 1 ELSE 0 END) as refunded")
            ->first();

        $conversions = (int) ($rows->conversions ?? 0);

        return $conversions >= (int) config('affiliates.rapid_refund.min_conversions', 3)
            && ((int) $rows->refunded) * 100 > $conversions * (int) config('affiliates.rapid_refund.max_refunded_share_percent', 30);
    }

    /**
     * A manager clears the review: the referral and its unpaid commissions
     * may be approved again.
     */
    public function clearFlags(AffiliateReferral $referral, PlatformUser $by, string $note): AffiliateReferral
    {
        DB::connection('landlord')->transaction(static function () use ($referral, $by): void {
            /** @var AffiliateReferral $locked */
            $locked = AffiliateReferral::query()->whereKey($referral->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === AffiliateReferral::REJECTED) {
                throw ApiException::unprocessable('referral_rejected', 'A rejected referral cannot be cleared.');
            }

            $locked->forceFill(['requires_review' => false, 'reviewed_by' => $by->id])->save();

            AffiliateCommission::query()
                ->where('affiliate_referral_id', $locked->id)
                ->whereIn('status', [AffiliateCommission::PENDING, AffiliateCommission::APPROVED])
                ->update(['requires_review' => false]);
        });

        ActivityRecorder::landlord('affiliates', 'Affiliate referral flags cleared', $referral, ['note' => $note], $by);

        return $referral->refresh();
    }

    private function ineligible(AffiliateReferral $referral, string $reason): void
    {
        $referral->status = AffiliateReferral::INELIGIBLE;
        $referral->ineligible_reason = $reason;
    }

    /**
     * Compares normalised emails in SQL, so no tenant list is loaded: the
     * local part without "+tag" (and without dots for Gmail) and the domain.
     */
    private function isExistingCustomer(string $normalizedOwner, string $exceptTenantId): bool
    {
        [$local, $domain] = explode('@', $normalizedOwner, 2) + [1 => ''];
        $localExpression = "SUBSTRING_INDEX(SUBSTRING_INDEX(LOWER(email), '@', 1), '+', 1)";
        $query = DB::connection('landlord')->table('tenants')->where('id', '!=', $exceptTenantId);

        if (AffiliateIdentity::isGmail($domain)) {
            return $query
                ->whereIn(DB::raw("SUBSTRING_INDEX(LOWER(email), '@', -1)"), AffiliateIdentity::gmailDomains())
                ->whereRaw("REPLACE({$localExpression}, '.', '') = ?", [$local])
                ->exists();
        }

        return $query
            ->whereRaw("SUBSTRING_INDEX(LOWER(email), '@', -1) = ?", [$domain])
            ->whereRaw("{$localExpression} = ?", [$local])
            ->exists();
    }

    /**
     * The affiliate's latest login IP hash and those of its logins in the
     * last 30 days (from the landlord activity log).
     *
     * @return list<string>
     */
    private function affiliateIpHashes(Affiliate $affiliate): array
    {
        $hashes = LandlordActivity::query()
            ->where('log_name', 'auth')
            ->where('causer_type', $affiliate->getMorphClass())
            ->where('causer_id', $affiliate->id)
            ->where('created_at', '>=', now()->subDays((int) config('affiliates.affiliate_login_ip_days', 30)))
            ->pluck('properties')
            ->map(static fn ($p): ?string => is_array($p) ? ($p['ip_hash'] ?? null) : ($p?->get('ip_hash')))
            ->filter()
            ->values()
            ->all();

        if ($affiliate->last_login_ip_hash !== null) {
            $hashes[] = $affiliate->last_login_ip_hash;
        }

        return array_values(array_unique(array_map('strval', $hashes)));
    }

    /**
     * @param  list<string>  $flags
     */
    public function announce(Affiliate $affiliate, AffiliateReferral $referral, array $flags): void
    {
        if ($flags === []) {
            return;
        }

        $managers = PlatformUser::query()->withPlatformRole('affiliate-manager')->where('is_active', true)->get();

        if ($managers->isNotEmpty()) {
            $this->notifications->dispatch('affiliate.referral_flagged', $managers, [
                'affiliate_name' => $affiliate->name,
                'tenant_name' => (string) DB::connection('landlord')->table('tenants')->where('id', $referral->tenant_id)->value('name'),
                'reason' => implode(', ', $flags),
            ], data: ['affiliate_referral_id' => $referral->id]);
        }
    }
}
