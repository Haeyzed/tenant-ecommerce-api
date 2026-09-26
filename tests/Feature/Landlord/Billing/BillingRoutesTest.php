<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Models\PlatformPaymentGateway;
use App\Modules\Billing\Services\PlatformCouponService;
use App\Modules\Plans\Models\Plan;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->seed(PlatformAccessSeeder::class);

    DB::connection('landlord')->table('countries')->insert(['id' => 1, 'iso2' => 'NG', 'iso3' => 'NGA', 'name' => 'Nigeria', 'phone_code' => '234', 'native' => 'Nigeria', 'region' => 'Africa', 'subregion' => 'Western Africa', 'latitude' => '10', 'longitude' => '8', 'emoji' => '-', 'emojiU' => '-']);
    DB::connection('landlord')->table('currencies')->insert([
        ['country_id' => 1, 'name' => 'Naira', 'code' => 'NGN', 'symbol' => 'N', 'symbol_native' => 'N'],
        ['country_id' => 1, 'name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'symbol_native' => '$'],
    ]);

    $this->admin = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->admin->assignRole('super-admin');
    $this->token = ['Authorization' => 'Bearer '.$this->admin->createToken('t', ['platform'])->plainTextToken];
});

it('saves platform credentials after validating them with the provider', function (): void {
    Http::fake(['api.paystack.co/balance' => Http::response(['status' => true, 'data' => []])]);

    $this->landlordJson('PUT', '/api/admin/payment-gateways/paystack/test', [
        'public_key' => 'pk_test_abcd1234',
        'secret_key' => 'sk_test_secret',
        'supported_currencies' => ['ngn', 'USD'],
    ], $this->token)
        ->assertOk()
        ->assertJsonPath('data.has_secret_key', true)
        ->assertJsonPath('data.public_key', '…1234')
        ->assertJsonPath('data.supported_currencies', ['NGN', 'USD'])
        ->assertJsonMissingPath('data.secret_key');

    expect(PlatformPaymentGateway::query()->value('credentials_verified_at'))->not->toBeNull();
});

it('rejects keys saved in the wrong mode slot', function (): void {
    Http::fake(['api.paystack.co/balance' => Http::response(['status' => true, 'data' => []])]);

    $this->landlordJson('PUT', '/api/admin/payment-gateways/paystack/test', [
        'secret_key' => 'sk_live_secret',
        'supported_currencies' => ['NGN'],
    ], $this->token)->assertStatus(422)->assertJsonPath('meta.error_code', 'payment_mode_mismatch');
});

it('refuses live credentials and live billing mode while live payments are disabled', function (): void {
    config(['app.payments_live_allowed' => false]);

    $this->landlordJson('PUT', '/api/admin/payment-gateways/stripe/live', ['secret_key' => 'sk_live_x', 'supported_currencies' => ['USD']], $this->token)
        ->assertForbidden()->assertJsonPath('meta.error_code', 'live_payments_disabled');

    $this->landlordJson('POST', '/api/admin/payment-gateways/mode', ['mode' => 'live', 'confirm' => true, 'reason' => 'Launch'], $this->token)
        ->assertForbidden();
});

it('requires confirmation and a validated live gateway to switch billing to live', function (): void {
    config(['app.payments_live_allowed' => true]);

    $this->landlordJson('POST', '/api/admin/payment-gateways/mode', ['mode' => 'live', 'reason' => 'Launch'], $this->token)->assertStatus(422);
    $this->landlordJson('POST', '/api/admin/payment-gateways/mode', ['mode' => 'live', 'confirm' => true, 'reason' => 'Launch'], $this->token)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'no_live_gateway');

    $this->platformGateway('stripe', 'live', ['USD']);

    $this->landlordJson('POST', '/api/admin/payment-gateways/mode', ['mode' => 'live', 'confirm' => true, 'reason' => 'Launch'], $this->token)
        ->assertOk()->assertJsonPath('data.billing_payment_mode', 'live');
});

it('denies gateway routes to roles without the permission', function (): void {
    $support = PlatformUser::query()->create(['name' => 'Support', 'email' => 'support@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $support->assignRole('support-staff');

    $this->landlordJson('GET', '/api/admin/payment-gateways', [], ['Authorization' => 'Bearer '.$support->createToken('t', ['platform'])->plainTextToken])
        ->assertForbidden();
});

it('requires an idempotency key to refund', function (): void {
    $this->platformGateway();
    $tenant = $this->createTenant('a');
    $subscription = $this->subscribe($tenant, 'basic');
    $charge = PaymentTransaction::query()->create([
        'tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'type' => 'charge', 'mode' => 'test', 'provider' => 'paystack',
        'reference' => 'SUB-X', 'provider_reference' => '321', 'amount' => '20.00', 'currency_code' => 'USD', 'status' => 'successful',
    ]);

    $this->landlordJson('POST', "/api/admin/payment-transactions/{$charge->id}/refund", ['reason' => 'x'], $this->token)->assertStatus(422);

    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 1, 'status' => 'processed']])]);

    $headers = $this->token + ['Idempotency-Key' => 'refund-key-0001'];
    $this->landlordJson('POST', "/api/admin/payment-transactions/{$charge->id}/refund", ['reason' => 'Duplicate charge'], $headers)
        ->assertCreated()->assertJsonPath('data.status', 'successful')->assertJsonPath('data.amount', '-20.0000');

    $this->landlordJson('POST', "/api/admin/payment-transactions/{$charge->id}/refund", ['reason' => 'Duplicate charge'], $headers)
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(PaymentTransaction::query()->where('type', 'refund')->count())->toBe(1);
});

it('validates coupons publicly without an account', function (): void {
    $this->seedPlans();
    app(PlatformCouponService::class)->create([
        'code' => 'WELCOME10', 'name' => 'Welcome', 'discount_type' => 'percentage', 'discount_value' => 10, 'duration' => 'repeating', 'duration_cycles' => 3,
    ], $this->admin);
    $price = Plan::query()->where('slug', 'basic')->firstOrFail()->prices()->where('billing_interval', 'monthly')->firstOrFail();

    $this->landlordJson('POST', '/api/platform-coupons/validate', ['code' => 'welcome10', 'plan_price_id' => $price->id])
        ->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.discount', '2.0000')->assertJsonPath('data.duration_cycles', 3);

    $this->landlordJson('POST', '/api/platform-coupons/validate', ['code' => 'NOPE', 'plan_price_id' => $price->id])
        ->assertOk()->assertJsonPath('data.valid', false)->assertJsonPath('data.reason', 'not_found');
});
