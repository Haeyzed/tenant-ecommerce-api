<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateClick;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Modules\Affiliates\Services\AffiliateTrackingService;
use App\Modules\Auth\Support\EmailVerificationLink;
use App\Modules\Billing\Services\PlatformCouponService;
use App\Modules\Legal\Models\LegalAcceptance;
use App\Modules\Legal\Models\LegalDocument;
use App\Modules\Legal\Services\LegalDocumentService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    Bus::fake([ProvisionTenantDatabase::class]);
    $this->seed(PlatformAccessSeeder::class);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Landlord);
    $this->seedPlans();
    $this->platformGateway('paystack', 'test');
    app(PlatformSettingsService::class)->set('affiliate_program_enabled', true);

    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);
    DB::connection('landlord')->table('currencies')->insert(['country_id' => 1, 'name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'symbol_native' => '$']);
    DB::connection('landlord')->table('timezones')->insert(['country_id' => 1, 'name' => 'Africa/Lagos']);

    $legal = app(LegalDocumentService::class);
    $this->terms = collect(['terms_of_service', 'privacy_policy'])->map(fn (string $type): LegalDocument => $legal->publish(
        $legal->createDraft(['document_type' => $type, 'version' => '2026-09-01', 'title' => $type, 'body' => 'Text', 'required_at_registration' => true]),
    ));
    $this->agreement = $legal->publish($legal->createDraft(['document_type' => 'affiliate_agreement', 'version' => '2026-09-01', 'title' => 'Affiliate agreement', 'body' => 'Rules']));

    $this->manager = PlatformUser::query()->create(['name' => 'Mia', 'email' => 'mia@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->manager->assignRole('affiliate-manager');
    $this->managerAuth = ['Authorization' => 'Bearer '.$this->manager->createToken('t', ['platform'])->plainTextToken];
});

function approvedAffiliate(string $email, string $code, array $attributes = []): Affiliate
{
    $affiliate = new Affiliate(['name' => 'Aff '.$code, 'email' => $email, 'password' => 'Secret123', 'promotion_methods' => 'Blog']);
    $affiliate->forceFill(array_merge([
        'public_id' => (string) Str::uuid(),
        'status' => Affiliate::APPROVED,
        'referral_code' => $code,
        'email_verified_at' => now(),
        'approved_at' => now(),
    ], $attributes))->save();

    return $affiliate;
}

function affiliateRegistration(array $overrides = []): array
{
    return array_merge([
        'business_name' => 'Zed Stores',
        'owner_name' => 'Zed Obi',
        'email' => 'owner@zedstores.test',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
        'country_id' => 1,
        'plan_price_id' => PlanPrice::query()->whereHas('plan', fn ($q) => $q->where('slug', 'basic'))->where('billing_interval', 'monthly')->value('id'),
        'accepted_legal_document_ids' => test()->terms->pluck('id')->all(),
    ], $overrides);
}

/**
 * Registers and verifies; returns the new tenant.
 */
function registerAndVerify(array $payload): Tenant
{
    $id = test()->landlordJson('POST', '/api/register', $payload)->assertStatus(202)->json('data.registration_id');
    $code = null;

    // Notifications are inspected in order, so this ends on the latest code.
    Notification::assertSentTo(new AnonymousNotifiable, TemplatedNotification::class, function (TemplatedNotification $n) use (&$code): bool {
        if ($n->key === 'tenant.registration_verification' && preg_match('/code is (\d{6})/', $n->body, $m) === 1) {
            $code = $m[1];
        }

        return true;
    });

    test()->landlordJson('POST', '/api/register/verify', ['registration_id' => $id, 'code' => $code])->assertOk();

    return Tenant::query()->where('email', strtolower($payload['email']))->firstOrFail();
}

it('takes an application, verifies it, and lets a manager approve it with a referral link', function (): void {
    $this->landlordJson('POST', '/api/affiliate/auth/apply', [
        'name' => 'Ann Promoter', 'email' => 'Ann@Promo.test', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        'promotion_methods' => 'Newsletter', 'accepted_legal_document_ids' => [$this->agreement->id],
    ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.referral_code', null);

    $affiliate = Affiliate::query()->where('email', 'ann@promo.test')->firstOrFail();

    expect(LegalAcceptance::query()->where('affiliate_id', $affiliate->id)->where('context', 'affiliate_application')->count())->toBe(1);
    Notification::assertSentTo($affiliate, TemplatedNotification::class, fn ($n): bool => $n->key === 'affiliate.email_verification');

    // Not shown for review before the email is verified.
    $this->landlordJson('POST', "/api/admin/affiliates/{$affiliate->id}/approve", [], $this->managerAuth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'affiliate_email_unverified');

    $this->landlordJson('POST', '/api/affiliate/auth/email/verify', EmailVerificationLink::parameters('affiliate', $affiliate->id, $affiliate->email))->assertOk();
    Notification::assertSentTo($this->manager, TemplatedNotification::class, fn ($n): bool => $n->key === 'affiliate.application_submitted');

    $this->landlordJson('POST', "/api/admin/affiliates/{$affiliate->id}/approve", ['referral_code' => 'ann2026'], $this->managerAuth)
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.referral_code', 'ANN2026')
        ->assertJsonPath('data.referral_link', 'http://localhost:3002/?ref=ANN2026');

    $token = $this->landlordJson('POST', '/api/affiliate/auth/login', ['email' => 'ann@promo.test', 'password' => 'Secret123'])
        ->assertOk()->json('data.token');

    $this->landlordJson('GET', '/api/affiliate/profile', [], ['Authorization' => 'Bearer '.$token])
        ->assertOk()->assertJsonPath('data.referral_code', 'ANN2026')->assertJsonMissingPath('data.promotion_methods');

    $this->landlordJson('POST', "/api/admin/affiliates/{$affiliate->id}/approve", [], $this->managerAuth)->assertStatus(422)->assertJsonPath('meta.error_code', 'invalid_transition');
});

it('refuses applications while the programme is off and sign-in for closed affiliates', function (): void {
    app(PlatformSettingsService::class)->set('affiliate_program_enabled', false);

    $this->landlordJson('POST', '/api/affiliate/auth/apply', [
        'name' => 'X', 'email' => 'x@promo.test', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        'promotion_methods' => 'Ads', 'accepted_legal_document_ids' => [$this->agreement->id],
    ])->assertStatus(503)->assertJsonPath('meta.error_code', 'affiliate_program_unavailable');

    approvedAffiliate('closed@promo.test', 'CLOSED1', ['status' => Affiliate::CLOSED]);
    $this->landlordJson('POST', '/api/affiliate/auth/login', ['email' => 'closed@promo.test', 'password' => 'Secret123'])->assertForbidden();
});

it('records unique visitor-day clicks and issues tamper-proof, expiring tokens', function (): void {
    approvedAffiliate('a@promo.test', 'ALPHA1');

    $this->landlordJson('POST', '/api/affiliate-clicks', ['code' => 'nope1234'])->assertNotFound();

    $first = $this->landlordJson('POST', '/api/affiliate-clicks', ['code' => 'alpha1', 'landing_path' => '/pricing?utm_source=x&secret=1', 'referrer' => 'https://Blog.Example/post'])
        ->assertOk()->json('data');
    $this->landlordJson('POST', '/api/affiliate-clicks', ['code' => 'ALPHA1', 'visitor_id' => $first['visitor_id']])->assertOk();

    $click = AffiliateClick::query()->sole();
    expect($click->landing_path)->toBe('/pricing')->and($click->referrer_host)->toBe('blog.example');

    $tracking = app(AffiliateTrackingService::class);
    expect($tracking->parseToken($first['referral_token'])?->id)->toBe($click->id)
        ->and($tracking->parseToken($first['referral_token'].'x'))->toBeNull()
        ->and($tracking->parseToken(strrev($first['referral_token'])))->toBeNull();

    $this->travel(31)->days();
    expect($tracking->parseToken($first['referral_token']))->toBeNull();

    app(PlatformSettingsService::class)->set('affiliate_program_enabled', false);
    $this->landlordJson('POST', '/api/affiliate-clicks', ['code' => 'ALPHA1'])->assertNotFound();
});

it('attributes to the latest touch and fixes it at verification', function (): void {
    $a = approvedAffiliate('a@promo.test', 'ALPHA1');
    $b = approvedAffiliate('b@promo.test', 'BRAVO1');

    // Link click on A, then a ref code for B on the registration page: B wins.
    $tokenA = $this->landlordJson('POST', '/api/affiliate-clicks', ['code' => 'ALPHA1'])->json('data.referral_token');
    $tenant = registerAndVerify(affiliateRegistration(['referral_token' => $tokenA, 'ref' => 'BRAVO1']));

    $referral = AffiliateReferral::query()->where('tenant_id', $tenant->id)->sole();
    expect($referral->affiliate_id)->toBe($b->id)
        ->and($referral->source)->toBe('registration_code')
        ->and($referral->status)->toBe('registered')
        ->and($referral->conversion_deadline?->toDateString())->toBe(now()->addDays(180)->toDateString());

    // A's coupon beats a later click token for B.
    $admin = PlatformUser::query()->create(['name' => 'C', 'email' => 'c@platform.test', 'password' => 'Secret123']);
    app(PlatformCouponService::class)->create(['code' => 'ALPHA10', 'name' => 'A', 'discount_type' => 'percentage', 'discount_value' => 10, 'duration' => 'once', 'affiliate_id' => $a->id], $admin);
    $tokenB = $this->landlordJson('POST', '/api/affiliate-clicks', ['code' => 'BRAVO1'])->json('data.referral_token');

    $second = registerAndVerify(affiliateRegistration(['business_name' => 'Yan Shop', 'email' => 'owner@yanshop.test', 'coupon_code' => 'ALPHA10', 'referral_token' => $tokenB]));

    expect(AffiliateReferral::query()->where('tenant_id', $second->id)->value('affiliate_id'))->toBe($a->id)
        ->and(AffiliateReferral::query()->where('tenant_id', $second->id)->value('source'))->toBe('coupon');

    // An invalid token never blocks registration.
    $third = registerAndVerify(affiliateRegistration(['business_name' => 'Xi Shop', 'email' => 'owner@xishop.test', 'referral_token' => 'garbage.token']));
    expect(AffiliateReferral::query()->where('tenant_id', $third->id)->exists())->toBeFalse();
});

it('makes self-referrals and existing customers ineligible and flags shared domains', function (): void {
    approvedAffiliate('Zed.Obi+aff@gmail.com', 'ZEDAFF');
    $self = registerAndVerify(affiliateRegistration(['email' => 'zedobi@gmail.com', 'ref' => 'ZEDAFF']));
    expect(AffiliateReferral::query()->where('tenant_id', $self->id)->value('ineligible_reason'))->toBe('self_referral');

    $this->createTenantRow('old');
    Tenant::query()->whereKey('test-tenant-old')->update(['email' => 'returning@shop.test', 'status' => 'closed']);
    approvedAffiliate('partner@agency.test', 'AGENCY');
    $existing = registerAndVerify(affiliateRegistration(['business_name' => 'Back Again', 'email' => 'Returning+2@shop.test', 'ref' => 'AGENCY']));
    expect(AffiliateReferral::query()->where('tenant_id', $existing->id)->value('ineligible_reason'))->toBe('existing_customer');

    $flagged = registerAndVerify(affiliateRegistration(['business_name' => 'Agency Client', 'email' => 'client@agency.test', 'ref' => 'AGENCY']));
    $referral = AffiliateReferral::query()->where('tenant_id', $flagged->id)->sole();
    expect($referral->status)->toBe('registered')
        ->and($referral->requires_review)->toBeTrue()
        ->and(array_column((array) $referral->risk_flags, 'flag'))->toBe(['email_domain_match']);
    Notification::assertSentTo($this->manager, TemplatedNotification::class, fn ($n): bool => $n->key === 'affiliate.referral_flagged');
});

it('keeps each affiliate to its own records and separates actor tokens', function (): void {
    $a = approvedAffiliate('a@promo.test', 'ALPHA1');
    $b = approvedAffiliate('b@promo.test', 'BRAVO1');
    $tenant = $this->createTenantRow('x');
    AffiliateReferral::query()->create(['affiliate_id' => $a->id, 'tenant_id' => $tenant, 'source' => 'link', 'status' => 'registered', 'attributed_at' => now()]);

    $bAuth = ['Authorization' => 'Bearer '.$b->createToken('t', ['affiliate'])->plainTextToken];
    $aAuth = ['Authorization' => 'Bearer '.$a->createToken('t', ['affiliate'])->plainTextToken];

    $this->landlordJson('GET', '/api/affiliate/referrals', [], $bAuth)->assertOk()->assertJsonCount(0, 'data');
    $this->landlordJson('GET', '/api/affiliate/referrals', [], $aAuth)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.business', 'Te*** X***')->assertJsonMissingPath('data.0.tenant_id');

    $this->landlordJson('GET', '/api/affiliate/profile', [], $this->managerAuth)->assertUnauthorized();
    $this->landlordJson('GET', '/api/admin/affiliates', [], $aAuth)->assertUnauthorized();
    $this->landlordJson('GET', '/api/admin/affiliates', [], $this->managerAuth)->assertOk()->assertJsonCount(2, 'data');
});
