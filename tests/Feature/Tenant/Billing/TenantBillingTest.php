<?php

declare(strict_types=1);

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Plans\Models\Plan;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->platformGateway('paystack', 'test');
    $this->tenant = $this->createTenant('a');
    $this->subscription = $this->subscribe($this->tenant, 'basic', SubscriptionStatus::Trialing, ['trial_ends_at' => now()->addDays(5), 'renews_at' => now()->addDays(5)]);

    tenancy()->initialize($this->tenant);
    $this->owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->owner->assignRole('owner');
    $this->headers = ['Authorization' => 'Bearer '.$this->owner->createToken('t', ['staff'])->plainTextToken];

    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/x', 'access_code' => 'x']])]);
});

it('lists plans priced in the billing currency with their providers', function (): void {
    $this->tenantJson('GET', '/api/admin/billing/plans', [], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'basic')
        ->assertJsonPath('data.0.prices.0.gateways', ['paystack']);
});

it('starts a checkout that pays the trial', function (): void {
    $plan = Plan::query()->where('slug', 'standard')->firstOrFail();

    $this->tenantJson('POST', '/api/admin/billing/subscription', [
        'plan_id' => $plan->id, 'billing_interval' => 'monthly', 'gateway' => 'paystack',
    ], $this->headers + ['Idempotency-Key' => 'checkout-0001'])
        ->assertCreated()
        ->assertJsonPath('data.checkout_url', 'https://checkout.paystack.test/x')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.subscription.plan.slug', 'standard');
});

it('refuses providers that cannot charge the currency', function (): void {
    $plan = Plan::query()->where('slug', 'standard')->firstOrFail();

    $this->tenantJson('POST', '/api/admin/billing/subscription', [
        'plan_id' => $plan->id, 'billing_interval' => 'monthly', 'gateway' => 'stripe',
    ], $this->headers + ['Idempotency-Key' => 'checkout-0002'])->assertStatus(422)->assertJsonPath('meta.error_code', 'gateway_unavailable');
});

it('shows the current subscription with its next cycle', function (): void {
    $this->tenantJson('GET', '/api/admin/billing/subscription', [], $this->headers)
        ->assertOk()
        ->assertJsonPath('data.subscription.status', 'trialing')
        ->assertJsonPath('data.next_cycle.total', '20.0000');
});

it('keeps billing reachable while admin writes are restricted', function (): void {
    $this->subscription->forceFill(['status' => SubscriptionStatus::PastDue, 'past_due_at' => now()->subDays(10)])->save();

    $this->tenantJson('PATCH', '/api/admin/auth/preferences', ['date_format' => 'DD/MM/YYYY'], $this->headers)->assertOk();
    $this->tenantJson('POST', '/api/admin/payment-settings/paystack/test/test', [], $this->headers)
        ->assertStatus(402)->assertJsonPath('meta.error_code', 'subscription_past_due');
    $this->tenantJson('GET', '/api/admin/billing/subscription', [], $this->headers)->assertOk();
});

it('reserves billing to roles holding billing permissions', function (): void {
    $clerk = User::query()->create(['name' => 'Clerk', 'email' => 'clerk@a.test', 'password' => 'Secret123', 'is_active' => true]);
    $clerk->assignRole('admin');

    $this->tenantJson('GET', '/api/admin/billing/subscription', [], ['Authorization' => 'Bearer '.$clerk->createToken('t', ['staff'])->plainTextToken])
        ->assertForbidden();
});

it('saves tenant gateway credentials and switches payment mode safely', function (): void {
    Http::fake(['api.paystack.co/balance' => Http::response(['status' => true, 'data' => []])]);

    $this->tenantJson('PUT', '/api/admin/payment-settings/paystack/test', ['secret_key' => 'sk_test_tenant', 'public_key' => 'pk_test_9876'], $this->headers)
        ->assertOk()->assertJsonPath('data.public_key', '…9876');

    $this->tenantJson('PATCH', '/api/admin/payment-settings/paystack/test/activate', [], $this->headers)->assertOk()->assertJsonPath('data.is_active', true);

    config(['app.payments_live_allowed' => true]);
    $this->tenantJson('PATCH', '/api/admin/payment-settings/mode', ['mode' => 'live'], $this->headers)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'no_live_gateway');
});
