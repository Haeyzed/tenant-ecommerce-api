<?php

declare(strict_types=1);

namespace App\Modules\Affiliates\Services;

use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateClick;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Modules\Billing\Models\PlatformCoupon;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Last-touch attribution, fixed on the server (spec §21A.4): the touch is
 * chosen at registration and the referral is created at email
 * verification. Attribution never blocks a registration.
 */
final readonly class AffiliateAttributionService
{
    public const string SOURCE_COUPON = 'coupon';

    public const string SOURCE_REGISTRATION_CODE = 'registration_code';

    public const string SOURCE_LINK = 'link';

    public function __construct(
        private AffiliateService $affiliates,
        private AffiliateTrackingService $tracking,
        private AffiliateFraudService $fraud,
        private PlatformSettingsService $settings,
    ) {}

    /**
     * The first valid touch among: an affiliate coupon typed at
     * registration, a `ref` code on the registration page, the referral
     * token from the cookie. Invalid or expired values are ignored.
     *
     * @param  array{ref?: string|null, referral_token?: string|null}  $input
     * @return array{affiliate_click_id: int|null, affiliate_source: string}|null
     */
    public function resolveForRegistration(array $input, ?PlatformCoupon $coupon, Request $request): ?array
    {
        if (! $this->affiliates->programmeEnabled()) {
            return null;
        }

        try {
            if ($coupon?->affiliate_id !== null && (bool) $this->settings->get('affiliate_coupon_attribution_enabled', true)
                && Affiliate::query()->whereKey($coupon->affiliate_id)->where('status', Affiliate::APPROVED)->exists()) {
                return ['affiliate_click_id' => null, 'affiliate_source' => self::SOURCE_COUPON];
            }

            if (filled($input['ref'] ?? null) && ($affiliate = $this->affiliates->resolveCode((string) $input['ref'])) !== null) {
                return ['affiliate_click_id' => $this->tracking->recordRegistrationClick($affiliate, $request)->id, 'affiliate_source' => self::SOURCE_REGISTRATION_CODE];
            }

            if (filled($input['referral_token'] ?? null)) {
                $click = $this->tracking->parseToken((string) $input['referral_token']);

                if ($click !== null && $click->affiliate->isApproved()) {
                    return ['affiliate_click_id' => $click->id, 'affiliate_source' => self::SOURCE_LINK];
                }
            }
        } catch (Throwable $e) {
            // Attribution must never block a sign-up.
            report($e);
        }

        return null;
    }

    /**
     * Creates the tenant's referral at email verification, inside the
     * verification transaction. Hard fraud rules make it ineligible; review
     * flags put it under review.
     */
    public function attachToTenant(TenantRegistration $registration, Tenant $tenant): ?AffiliateReferral
    {
        $affiliateId = match ($registration->affiliate_source) {
            self::SOURCE_COUPON => PlatformCoupon::query()->whereKey($registration->platform_coupon_id)->value('affiliate_id'),
            self::SOURCE_LINK, self::SOURCE_REGISTRATION_CODE => AffiliateClick::query()->whereKey($registration->affiliate_click_id)->value('affiliate_id'),
            default => null,
        };

        /** @var Affiliate|null $affiliate */
        $affiliate = $affiliateId === null ? null : Affiliate::query()->find($affiliateId);

        if ($affiliate === null || AffiliateReferral::query()->where('tenant_id', $tenant->getTenantKey())->exists()) {
            return null;
        }

        $window = $this->settings->get('affiliate_conversion_window_days', 180);

        $referral = new AffiliateReferral([
            'affiliate_id' => $affiliate->id,
            'tenant_id' => $tenant->getTenantKey(),
            'tenant_registration_id' => $registration->id,
            'affiliate_click_id' => $registration->affiliate_source === self::SOURCE_COUPON ? null : $registration->affiliate_click_id,
            'platform_coupon_id' => $registration->affiliate_source === self::SOURCE_COUPON ? $registration->platform_coupon_id : null,
            'source' => $registration->affiliate_source,
            'status' => AffiliateReferral::REGISTERED,
            'requires_review' => false,
            'attributed_at' => now(),
            'conversion_deadline' => $window === null || $window === '' ? null : now()->addDays((int) $window),
        ]);

        $flags = [];

        if (! $affiliate->isApproved()) {
            $referral->status = AffiliateReferral::INELIGIBLE;
            $referral->ineligible_reason = 'affiliate_not_active';
        } else {
            $flags = $this->fraud->evaluateReferral($referral, $affiliate, $registration, $tenant->email);
        }

        $referral->save();
        $this->fraud->announce($affiliate, $referral, $flags);

        Log::info('Affiliate referral attributed.', ['affiliate_id' => $affiliate->id, 'tenant_id' => $tenant->getTenantKey(), 'source' => $referral->source, 'status' => $referral->status]);

        return $referral;
    }

    /**
     * Registered referrals past their conversion window become
     * ineligible (daily landlord maintenance).
     */
    public function expireStaleReferrals(): int
    {
        return AffiliateReferral::query()
            ->where('status', AffiliateReferral::REGISTERED)
            ->whereNotNull('conversion_deadline')
            ->where('conversion_deadline', '<', now())
            ->update(['status' => AffiliateReferral::INELIGIBLE, 'ineligible_reason' => 'conversion_window_expired', 'updated_at' => now()]);
    }
}
