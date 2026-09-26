<?php

declare(strict_types=1);

use App\Modules\Legal\Models\LegalAcceptance;
use App\Modules\Legal\Models\LegalDocument;
use App\Modules\Legal\Services\LegalDocumentService;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantRegistration;
use App\Modules\Tenancy\Services\TenantRegistrationService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->seedPlans();
    $this->platformGateway('paystack', 'test');

    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);
    DB::connection('landlord')->table('currencies')->insert(['country_id' => 1, 'name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'symbol_native' => '$']);
    DB::connection('landlord')->table('timezones')->insert(['country_id' => 1, 'name' => 'Africa/Lagos']);

    $legal = app(LegalDocumentService::class);
    $this->documents = collect(['terms_of_service', 'privacy_policy'])->map(function (string $type) use ($legal): LegalDocument {
        $document = $legal->createDraft(['document_type' => $type, 'version' => '2026-09-01', 'title' => $type, 'body' => 'Text', 'required_at_registration' => true]);

        return $legal->publish($document);
    });
});

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'business_name' => 'Ada Stores',
        'owner_name' => 'Ada Obi',
        'email' => 'Ada@Example.test',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
        'country_id' => 1,
        'plan_price_id' => PlanPrice::query()->whereHas('plan', fn ($q) => $q->where('slug', 'basic'))->where('billing_interval', 'monthly')->value('id'),
        'accepted_legal_document_ids' => test()->documents->pluck('id')->all(),
    ], $overrides);
}

function sentCode(): string
{
    $code = null;

    Notification::assertSentTo(new AnonymousNotifiable, TemplatedNotification::class, function (TemplatedNotification $n) use (&$code): bool {
        preg_match('/code is (\d{6})/', $n->body, $m);
        $code = $m[1] ?? $code;

        return $n->key === 'tenant.registration_verification';
    });

    return (string) $code;
}

it('registers, records legal acceptance and sends a verification code', function (): void {
    $id = $this->landlordJson('POST', '/api/register', registrationPayload())
        ->assertStatus(202)->assertJsonPath('data.status', 'pending_verification')->json('data.registration_id');

    $registration = TenantRegistration::query()->where('public_id', $id)->firstOrFail();

    expect($registration->email)->toBe('ada@example.test')
        ->and($registration->slug)->toBe('ada-stores')
        ->and($registration->default_currency)->toBe('USD')
        ->and(Hash::check('Secret123', (string) $registration->password_hash))->toBeTrue()
        ->and(LegalAcceptance::query()->where('tenant_registration_id', $registration->id)->count())->toBe(2)
        ->and(sentCode())->toMatch('/^\d{6}$/');
});

it('refuses registration while switched off or without published terms', function (): void {
    app(PlatformSettingsService::class)->set('tenant_registration_enabled', false);
    $this->landlordJson('POST', '/api/register', registrationPayload())->assertStatus(503)->assertJsonPath('meta.error_code', 'registration_unavailable');

    app(PlatformSettingsService::class)->set('tenant_registration_enabled', true);
    LegalDocument::query()->update(['status' => 'retired']);
    $this->landlordJson('POST', '/api/register', registrationPayload())->assertStatus(503);
});

it('rejects an outdated legal version with the current ones', function (): void {
    $legal = app(LegalDocumentService::class);
    $legal->publish($legal->createDraft(['document_type' => 'terms_of_service', 'version' => '2026-10-01', 'title' => 'Terms v2', 'body' => 'New', 'required_at_registration' => true]));

    $this->landlordJson('POST', '/api/register', registrationPayload())
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'legal_version_outdated')->assertJsonCount(2, 'meta.details.current');
});

it('allows one live store per email', function (): void {
    $this->landlordJson('POST', '/api/register', registrationPayload())->assertStatus(202);
    $this->landlordJson('POST', '/api/register', registrationPayload(['business_name' => 'Other']))->assertStatus(422)->assertJsonValidationErrors('email');
});

it('limits wrong codes and converts on the right one into a provisioning trial', function (): void {
    Bus::fake([ProvisionTenantDatabase::class]);
    $id = $this->landlordJson('POST', '/api/register', registrationPayload())->json('data.registration_id');
    $code = sentCode();

    $this->landlordJson('POST', '/api/register/verify', ['registration_id' => $id, 'code' => $code === '000000' ? '111111' : '000000'])
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'verification_code_invalid')->assertJsonPath('meta.details.attempts_left', 4);

    $this->landlordJson('POST', '/api/register/verify', ['registration_id' => $id, 'code' => $code])
        ->assertOk()
        ->assertJsonPath('data.tenant_status', 'provisioning')
        ->assertJsonPath('data.next_action', 'none')
        ->assertJsonPath('data.domain', 'ada-stores.platform.test');

    $tenant = Tenant::query()->where('slug', 'ada-stores')->firstOrFail();

    expect($tenant->timezone)->toBe('Africa/Lagos')
        ->and(LegalAcceptance::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and($tenant->trial_consumed_at)->not->toBeNull();

    Bus::assertDispatched(ProvisionTenantDatabase::class, fn (ProvisionTenantDatabase $job): bool => $job->tenantId === $tenant->id);

    $this->landlordJson('GET', "/api/register/{$id}/status")->assertOk()->assertJsonPath('data.registration_status', 'converted');
});

it('asks for payment when the price has no trial', function (): void {
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/reg', 'access_code' => 'x']])]);
    $price = PlanPrice::query()->whereHas('plan', fn ($q) => $q->where('slug', 'standard'))->where('billing_interval', 'monthly')->value('id');

    $id = $this->landlordJson('POST', '/api/register', registrationPayload(['plan_price_id' => $price]))->json('data.registration_id');

    $this->landlordJson('POST', '/api/register/verify', ['registration_id' => $id, 'code' => sentCode()])
        ->assertOk()
        ->assertJsonPath('data.tenant_status', 'awaiting_payment')
        ->assertJsonPath('data.next_action', 'payment')
        ->assertJsonPath('data.checkout_url', 'https://checkout.paystack.test/reg');
});

it('closes tenants that never paid, releasing nothing but landlord rows', function (): void {
    $price = PlanPrice::query()->whereHas('plan', fn ($q) => $q->where('slug', 'standard'))->where('billing_interval', 'monthly')->value('id');
    Http::fake(['*' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://x.test', 'access_code' => 'x']])]);
    $id = $this->landlordJson('POST', '/api/register', registrationPayload(['plan_price_id' => $price]))->json('data.registration_id');
    $this->landlordJson('POST', '/api/register/verify', ['registration_id' => $id, 'code' => sentCode()])->assertOk();

    $this->travel(8)->days();
    app(TenantRegistrationService::class)->closeUnpaidTenants();

    $tenant = Tenant::query()->where('slug', 'ada-stores')->firstOrFail();
    expect($tenant->status)->toBe(TenantStatus::Closed)->and($tenant->status_reason)->toBe('registration_unpaid');
});

it('provisions a real tenant database end to end and lets the owner sign in', function (): void {
    Bus::fake([ProvisionTenantDatabase::class]);
    $id = $this->landlordJson('POST', '/api/register', registrationPayload())->json('data.registration_id');
    $this->landlordJson('POST', '/api/register/verify', ['registration_id' => $id, 'code' => sentCode()])->assertOk();

    $tenant = Tenant::query()->where('slug', 'ada-stores')->firstOrFail();
    $database = $tenant->database()->getName();
    static::$wrapTenantTransactions = false;

    try {
        (new ProvisionTenantDatabase($tenant->id, TenantRegistration::query()->where('public_id', $id)->value('id')))
            ->handle(app(TenantRegistrationService::class), app(PlatformSettingsService::class));

        expect($tenant->refresh()->status)->toBe(TenantStatus::Active)
            ->and($tenant->provisioned_at)->not->toBeNull()
            ->and(TenantRegistration::query()->where('public_id', $id)->value('password_hash'))->toBeNull();

        $this->subscribe($tenant, 'basic');
        $this->json('POST', 'http://ada-stores.platform.test/api/admin/auth/login', ['email' => 'ada@example.test', 'password' => 'Secret123'])
            ->assertOk()->assertJsonPath('data.user.name', 'Ada Obi');

        tenancy()->initialize($tenant);
        expect(app(TenantSettingsService::class)->get('store_name'))->toBe('Ada Stores');
    } finally {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        static::$wrapTenantTransactions = true;
        DB::connection('tenant_template')->statement("drop database if exists `{$database}`");
    }
});
