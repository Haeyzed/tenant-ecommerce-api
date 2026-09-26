<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Affiliates\Services\AffiliateAttributionService;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\PlatformCoupon;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\PlatformCouponService;
use App\Modules\Billing\Services\PlatformPaymentGatewayService;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Legal\Models\LegalAcceptance;
use App\Modules\Legal\Services\LegalDocumentService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Plans\Services\ModuleActivationService;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Events\TenantProvisioned;
use App\Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantRegistration;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Sign-up, email verification, first payment and provisioning (spec §9.3,
 * §9.4, §9.7). No tenant or database exists before verification.
 */
final readonly class TenantRegistrationService
{
    /**
     * Subdomains that can never be tenant slugs.
     *
     * @var list<string>
     */
    private const array RESERVED_SLUGS = [
        'www', 'api', 'app', 'admin', 'mail', 'smtp', 'ftp', 'static', 'assets', 'cdn', 'docs', 'help', 'support', 'status',
        'blog', 'affiliate', 'affiliates', 'billing', 'login', 'register', 'dashboard', 'platform', 'root', 'system', 'test',
    ];

    public function __construct(
        private PlatformSettingsService $settings,
        private LegalDocumentService $legal,
        private PlatformCouponService $coupons,
        private SubscriptionService $subscriptions,
        private PlatformPaymentGatewayService $gateways,
        private NotificationDispatchService $notifications,
        private TenantDatabasePreparer $preparer,
        private DatabasePlacementService $placement,
        private ModuleActivationService $activation,
        private AffiliateAttributionService $attribution,
    ) {}

    /**
     * Step 1: records the sign-up and sends the verification code.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, Request $request): TenantRegistration
    {
        if (! (bool) $this->settings->get('tenant_registration_enabled', true) || ! $this->legal->registrationDocumentsReady()) {
            throw new ApiException('registration_unavailable', 'Sign-up is not available right now.', 503);
        }

        $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));

        $validated = validator($data, [
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'owner_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'country_id' => ['required', 'integer', Rule::exists('landlord.countries', 'id')],
            'default_currency' => ['sometimes', 'nullable', 'string', 'size:3', Rule::exists('landlord.currencies', 'code')],
            'plan_price_id' => ['required', 'integer'],
            'accepted_legal_document_ids' => ['required', 'array', 'min:1'],
            'accepted_legal_document_ids.*' => ['integer'],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'referral_token' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ref' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        $this->assertEmailAvailable($validated['email']);

        $price = PlanPrice::query()
            ->where('is_active', true)
            ->whereHas('plan', static fn ($q) => $q->where('is_active', true)->where('is_public', true))
            ->find((int) $validated['plan_price_id']);

        if ($price === null) {
            throw ValidationException::withMessages(['plan_price_id' => ['Choose an available plan.']]);
        }

        $this->legal->assertAcceptedCurrentRequired(array_map('intval', $validated['accepted_legal_document_ids']));

        $coupon = null;

        if (filled($validated['coupon_code'] ?? null)) {
            $result = $this->coupons->validateForRegistration((string) $validated['coupon_code'], $price, $validated['email']);

            if (! $result['valid']) {
                throw ApiException::unprocessable('coupon_invalid', 'This coupon cannot be applied.', ['reason' => $result['reason']]);
            }

            $coupon = $result['coupon'];
        }

        $code = $this->newCode();

        // Last-touch affiliate attribution (§21A.4); never blocks sign-up.
        $attribution = $this->attribution->resolveForRegistration(
            ['ref' => $validated['ref'] ?? null, 'referral_token' => $validated['referral_token'] ?? null],
            $coupon,
            $request,
        );

        return DB::connection('landlord')->transaction(function () use ($validated, $price, $coupon, $code, $request, $attribution): TenantRegistration {
            /** @var TenantRegistration $registration */
            $registration = TenantRegistration::query()->create([
                'public_id' => (string) Str::uuid(),
                'business_name' => trim($validated['business_name']),
                'slug' => $this->uniqueSlug($validated['business_name']),
                'owner_name' => trim($validated['owner_name']),
                'email' => $validated['email'],
                'password_hash' => Hash::make($validated['password']),
                'country_id' => (int) $validated['country_id'],
                'default_currency' => strtoupper((string) ($validated['default_currency'] ?? $this->countryCurrency((int) $validated['country_id']))),
                'plan_price_id' => $price->id,
                'platform_coupon_id' => $coupon?->id,
                'affiliate_click_id' => $attribution['affiliate_click_id'] ?? null,
                'affiliate_source' => $attribution['affiliate_source'] ?? null,
                'verification_token_hash' => hash('sha256', $code),
                'verification_expires_at' => now()->addHours((int) $this->settings->get('registration_verification_hours', 24)),
                'status' => TenantRegistration::PENDING,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            ]);

            $this->legal->recordAcceptances(
                array_map('intval', $validated['accepted_legal_document_ids']),
                ['tenant_registration_id' => $registration->id, 'name' => $registration->owner_name, 'email' => $registration->email],
                'registration',
                $request,
            );

            $this->sendCode($registration, $code);

            return $registration;
        });
    }

    /**
     * A fresh code invalidates the previous one; at most one per minute.
     */
    public function resendVerification(TenantRegistration $registration): void
    {
        if ($registration->status !== TenantRegistration::PENDING) {
            throw ApiException::unprocessable('registration_not_pending', 'This registration is no longer awaiting verification.');
        }

        if (! Cache::store('landlord')->add('registration-resend:'.$registration->id, true, 60)) {
            throw new ApiException('too_many_requests', 'Please wait a minute before requesting another code.', 429);
        }

        $code = $this->newCode();

        $registration->forceFill([
            'verification_token_hash' => hash('sha256', $code),
            'verification_attempts' => 0,
            'verification_expires_at' => now()->addHours((int) $this->settings->get('registration_verification_hours', 24)),
        ])->save();

        $this->sendCode($registration, $code);
    }

    /**
     * Step 2: converts the registration into a tenant and its subscription.
     *
     * @return array{tenant_id: string, domain: string, tenant_status: string, next_action: string, checkout_url: string|null}
     */
    public function verify(TenantRegistration $registration, string $code): array
    {
        [$tenant, $subscription] = DB::connection('landlord')->transaction(function () use ($registration, $code): array {
            /** @var TenantRegistration $locked */
            $locked = TenantRegistration::query()->whereKey($registration->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === TenantRegistration::CONVERTED) {
                throw ApiException::unprocessable('registration_already_verified', 'This registration has already been verified.');
            }

            if ($locked->status === TenantRegistration::EXPIRED || $locked->verification_expires_at->isPast()) {
                throw ApiException::unprocessable('verification_expired', 'This code has expired. Request a new one.');
            }

            if ($locked->verification_attempts >= TenantRegistration::MAX_ATTEMPTS) {
                throw ApiException::unprocessable('verification_code_invalid', 'Too many wrong codes. Request a new one.');
            }

            if (! hash_equals($locked->verification_token_hash, hash('sha256', trim($code)))) {
                $locked->increment('verification_attempts');

                throw ApiException::unprocessable('verification_code_invalid', 'The code is not correct.', [
                    'attempts_left' => max(0, TenantRegistration::MAX_ATTEMPTS - $locked->verification_attempts),
                ]);
            }

            $this->assertEmailAvailable($locked->email, $locked->id);

            $tenant = new Tenant([
                'id' => (string) Str::uuid(),
                'name' => $locked->business_name,
                'slug' => $this->uniqueSlug($locked->slug, $locked->id),
                'owner_name' => $locked->owner_name,
                'email' => $locked->email,
                'status' => TenantStatus::AwaitingPayment,
                'timezone' => $this->countryTimezone($locked->country_id),
                'country_id' => $locked->country_id,
                'default_currency' => $locked->default_currency,
            ]);
            $tenant->save();

            Domain::query()->create([
                'domain' => $tenant->slug.'.'.config('tenancy.root_domain'),
                'tenant_id' => $tenant->id,
                'type' => 'subdomain',
                'is_primary' => true,
                'status' => 'active',
                'verified_at' => now(),
            ]);

            LegalAcceptance::query()->where('tenant_registration_id', $locked->id)->update(['tenant_id' => $tenant->id]);

            // Attribution becomes permanent here (§21A.4, §9.3 step 2.4).
            $this->attribution->attachToTenant($locked, $tenant);

            $coupon = $locked->platform_coupon_id !== null ? PlatformCoupon::query()->find($locked->platform_coupon_id) : null;
            $subscription = $this->subscriptions->createInitialSubscription($tenant, $locked->planPrice, $coupon);

            $ready = in_array($subscription->status, [SubscriptionStatus::Trialing, SubscriptionStatus::Active], true);
            $tenant->forceFill(['status' => $ready ? TenantStatus::Provisioning : TenantStatus::AwaitingPayment])->save();

            $locked->forceFill(['status' => TenantRegistration::CONVERTED, 'verified_at' => now(), 'tenant_id' => $tenant->id])->save();

            if ($ready) {
                ProvisionTenantDatabase::dispatch($tenant->id, $locked->id)->afterCommit();
            }

            return [$tenant, $subscription];
        });

        $checkoutUrl = null;

        if ($tenant->status === TenantStatus::AwaitingPayment) {
            $gateway = $this->gateways->availableFor($tenant, $subscription->currency_code)->first();

            if ($gateway !== null) {
                $checkoutUrl = $this->createCheckout($registration->refresh(), $gateway->provider)['checkout_url'];
            }
        }

        return [
            'tenant_id' => (string) $tenant->id,
            'domain' => $tenant->slug.'.'.config('tenancy.root_domain'),
            'tenant_status' => $tenant->status->value,
            'next_action' => $tenant->status === TenantStatus::AwaitingPayment ? 'payment' : 'none',
            'checkout_url' => $checkoutUrl,
        ];
    }

    /**
     * Step 3: a checkout for a tenant awaiting its first payment.
     *
     * @return array{checkout_url: string|null, reference: string}
     */
    public function createCheckout(TenantRegistration $registration, string $gateway): array
    {
        $tenant = $registration->tenant;

        if ($tenant === null || $tenant->status !== TenantStatus::AwaitingPayment) {
            throw ApiException::unprocessable('checkout_unavailable', 'This registration does not need a payment.');
        }

        $subscription = $this->subscriptions->getCurrentSubscription($tenant) ?? throw ApiException::unprocessable('checkout_unavailable', 'No subscription to pay.');
        $subscription->loadMissing('planPrice.plan');

        $result = $this->subscriptions->subscribeTenantToPlan($tenant, $subscription->planPrice->plan, $subscription->billing_interval, $gateway);

        return ['checkout_url' => $result['checkout_url'], 'reference' => $result['reference']];
    }

    /**
     * Housekeeping (§9.3): expire stale registrations, and delete expired
     * and converted ones after 30 days (with the unconverted ones'
     * acceptances, because no contract was formed).
     */
    public function expireStaleRegistrations(): int
    {
        $expired = TenantRegistration::query()
            ->where('status', TenantRegistration::PENDING)
            ->where('verification_expires_at', '<', now())
            ->update(['status' => TenantRegistration::EXPIRED, 'password_hash' => null]);

        TenantRegistration::query()
            ->whereIn('status', [TenantRegistration::EXPIRED, TenantRegistration::CONVERTED])
            ->where('updated_at', '<', now()->subDays(30))
            ->chunkById(200, static function ($registrations): void {
                foreach ($registrations as $registration) {
                    DB::connection('landlord')->transaction(static function () use ($registration): void {
                        LegalAcceptance::query()->where('tenant_registration_id', $registration->id)->whereNull('tenant_id')->delete();
                        $registration->delete();
                    });
                }
            });

        return $expired;
    }

    /**
     * Tenants still unpaid after unpaid_registration_expiry_days are closed
     * for immediate purge; their reservation is released.
     */
    public function closeUnpaidTenants(): int
    {
        $days = (int) $this->settings->get('unpaid_registration_expiry_days', 7);
        $closed = 0;

        Tenant::query()
            ->where('status', TenantStatus::AwaitingPayment->value)
            ->where('created_at', '<', now()->subDays($days))
            ->chunkById(100, function ($tenants) use (&$closed): void {
                foreach ($tenants as $tenant) {
                    if (Subscription::current((string) $tenant->id) !== null) {
                        $this->subscriptions->cancelTenantSubscription($tenant);
                    }

                    $tenant->forceFill([
                        'status' => TenantStatus::Closed,
                        'status_reason' => 'registration_unpaid',
                        'closed_at' => now(),
                        'purge_after' => now(),
                    ])->save();
                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * The steps of §9.4, each idempotent so a retry is safe.
     */
    public function provision(Tenant $tenant, ?TenantRegistration $registration): void
    {
        // 1–3: placement, database, migrations and default data.
        $this->placement->place($tenant);
        $this->placement->createDatabase($tenant);
        $this->preparer->prepare($tenant);

        $tenant->run(function () use ($tenant, $registration): void {
            // 4: settings that differ from their documented defaults.
            $settings = app(TenantSettingsService::class);
            $settings->set('store_name', $tenant->name);
            $settings->set('store_contact_email', $tenant->email);
            $settings->set('default_currency', $tenant->default_currency);
            $settings->set('timezone', $tenant->timezone);

            // 5: the owner, created once; the stored hash is cleared after.
            if ($registration !== null && ! User::query()->where('email', $tenant->email)->exists()) {
                if ($registration->password_hash === null) {
                    throw new \RuntimeException('The registration password hash is no longer available.');
                }

                $owner = new User([
                    'name' => $tenant->owner_name,
                    'email' => $tenant->email,
                    'is_active' => true,
                ]);
                // Already a bcrypt hash; the "hashed" cast keeps it as is.
                $owner->password = $registration->password_hash;
                $owner->forceFill(['email_verified_at' => $registration->verified_at])->save();
                $owner->assignRole('owner');
            }
        });

        $registration?->forceFill(['password_hash' => null])->save();

        // 6: the plan's auto modules.
        $this->activation->syncAutoModules($tenant);

        // 7: active.
        $tenant->forceFill(['status' => TenantStatus::Active, 'provisioned_at' => now()])->save();

        event(new TenantProvisioned($tenant));
    }

    private function sendCode(TenantRegistration $registration, string $code): void
    {
        $this->notifications->dispatch('tenant.registration_verification', Notification::route('mail', $registration->email), [
            'owner_name' => $registration->owner_name,
            'code' => $code,
            'expires_in_hours' => (int) $this->settings->get('registration_verification_hours', 24),
            'verification_url' => FrontendUrl::platformAdmin('/register/verify', ['registration' => $registration->public_id, 'code' => $code]),
        ]);
    }

    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * One live store per email (Assumption A-72).
     */
    private function assertEmailAvailable(string $email, ?int $ignoreRegistrationId = null): void
    {
        $taken = Tenant::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->whereNotIn('status', [TenantStatus::Closed->value, TenantStatus::Purged->value])
            ->exists()
            || TenantRegistration::query()
                ->where('email', $email)
                ->where('status', TenantRegistration::PENDING)
                ->where('verification_expires_at', '>', now())
                ->when($ignoreRegistrationId !== null, static fn ($q) => $q->where('id', '!=', $ignoreRegistrationId))
                ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['email' => ['This email already has a store or a pending sign-up.']]);
        }
    }

    private function uniqueSlug(string $name, ?int $ignoreRegistrationId = null): string
    {
        $base = Str::limit(Str::slug($name), 40, '') ?: 'store';

        if (in_array($base, self::RESERVED_SLUGS, true)) {
            $base .= '-store';
        }

        $slug = $base;
        $i = 2;

        while (Tenant::query()->where('slug', $slug)->exists()
            || Domain::query()->where('domain', $slug.'.'.config('tenancy.root_domain'))->exists()
            || TenantRegistration::query()->where('slug', $slug)->where('status', TenantRegistration::PENDING)
                ->when($ignoreRegistrationId !== null, static fn ($q) => $q->where('id', '!=', $ignoreRegistrationId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function countryCurrency(int $countryId): string
    {
        return (string) (DB::connection('landlord')->table('currencies')->where('country_id', $countryId)->orderBy('id')->value('code')
            ?? $this->settings->get('default_currency', 'USD'));
    }

    private function countryTimezone(int $countryId): string
    {
        return (string) (DB::connection('landlord')->table('timezones')->where('country_id', $countryId)->orderBy('id')->value('name')
            ?? $this->settings->get('default_timezone', 'UTC'));
    }
}
