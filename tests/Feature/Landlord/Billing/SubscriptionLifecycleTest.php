<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Jobs\ProcessSubscriptionRenewal;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Models\PlatformCoupon;
use App\Modules\Billing\Models\PlatformCouponRedemption;
use App\Modules\Billing\Models\SubscriptionMrrMovement;
use App\Modules\Billing\Services\PlatformCouponService;
use App\Modules\Billing\Services\SubscriptionAccessService;
use App\Modules\Billing\Services\SubscriptionBillingService;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Notifications\Services\NotificationDispatchService;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanPrice;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Jobs\ProvisionTenantDatabase;
use App\Modules\Users\Models\User;
use App\Shared\Exceptions\ApiException;
use App\Shared\Payments\WebhookLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->seedPlans();
    $this->platformGateway('paystack', 'test');
    $this->tenant = $this->createTenant('a');
    $this->service = app(SubscriptionService::class);
    $this->price = fn (string $slug, string $interval = 'monthly'): PlanPrice => PlanPrice::query()
        ->whereHas('plan', fn ($q) => $q->where('slug', $slug))->where('billing_interval', $interval)->where('is_active', true)->firstOrFail();

    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/abc', 'access_code' => 'abc']]),
    ]);
});

function coupon(array $attributes = []): PlatformCoupon
{
    $admin = PlatformUser::query()->firstOrCreate(['email' => 'coupons@platform.test'], ['name' => 'Coupons', 'password' => 'Secret123']);

    return app(PlatformCouponService::class)->create(array_merge([
        'code' => 'LAUNCH50', 'name' => 'Launch', 'discount_type' => 'percentage', 'discount_value' => 50, 'duration' => 'once',
    ], $attributes), $admin);
}

it('starts a trial once per owner email and snapshots it', function (): void {
    $subscription = $this->service->createInitialSubscription($this->tenant, ($this->price)('basic'));

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_days)->toBe(7)
        ->and($subscription->gateway_mode)->toBe('test')
        ->and($this->tenant->refresh()->trial_consumed_at)->not->toBeNull();

    $other = $this->createTenant('b', ['email' => strtoupper('owner-a@example.test')]);

    expect($this->service->createInitialSubscription($other, ($this->price)('basic'))->status)->toBe(SubscriptionStatus::Incomplete);
});

it('charges the first cycle through checkout and activates on the signed webhook', function (): void {
    $result = $this->service->subscribeTenantToPlan($this->tenant, Plan::query()->where('slug', 'standard')->firstOrFail(), 'monthly', 'paystack');

    expect($result['checkout_url'])->toBe('https://checkout.paystack.test/abc')
        ->and($result['subscription']->status)->toBe(SubscriptionStatus::Incomplete);

    Http::assertSent(fn (Request $r): bool => $r['reference'] === $result['reference'] && $r['amount'] === 3900);

    $this->paystackWebhook($this->paystackChargeSuccess($result['reference'], 3900))->assertOk();

    $subscription = $result['subscription']->refresh();
    $charge = PaymentTransaction::query()->where('reference', $result['reference'])->firstOrFail();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->authorization_reference)->toBe('AUTH_test123')
        ->and($subscription->renews_at->isSameDay(now()->addMonth()))->toBeTrue()
        ->and($charge->status)->toBe('successful')
        ->and($charge->fee)->toBe('1.5000')
        // Test mode never counts as a first paid charge or as MRR.
        ->and($charge->is_first_paid_charge)->toBeFalse()
        ->and(SubscriptionMrrMovement::query()->count())->toBe(0)
        ->and(WebhookLog::landlord()->whereNotNull('processed_at')->count())->toBe(1);
});

it('acknowledges a duplicate delivery without processing it twice', function (): void {
    $result = $this->service->subscribeTenantToPlan($this->tenant, Plan::query()->where('slug', 'standard')->firstOrFail(), 'monthly', 'paystack');
    $payload = $this->paystackChargeSuccess($result['reference'], 3900);

    $this->paystackWebhook($payload)->assertOk();
    $this->paystackWebhook($payload)->assertOk()->assertJsonPath('duplicate', true);

    expect(WebhookLog::landlord()->count())->toBe(1);
});

it('rejects forged webhooks and never stores them', function (): void {
    $this->paystackWebhook(['event' => 'charge.success', 'data' => []], 'test', 'sk_test_wrong')->assertUnauthorized();
    $this->paystackWebhook(['event' => 'charge.success', 'data' => []], 'live')->assertUnauthorized();

    expect(WebhookLog::landlord()->count())->toBe(0);
});

it('ignores an event whose own mode contradicts the URL', function (): void {
    $result = $this->service->subscribeTenantToPlan($this->tenant, Plan::query()->where('slug', 'standard')->firstOrFail(), 'monthly', 'paystack');

    $this->paystackWebhook($this->paystackChargeSuccess($result['reference'], 3900, overrides: ['domain' => 'live']))->assertOk();

    expect(WebhookLog::landlord()->value('error'))->toBe('mode_mismatch')
        ->and(PaymentTransaction::query()->where('reference', $result['reference'])->value('status'))->toBe('pending');
});

it('leaves a charge pending when the provider reports another amount', function (): void {
    $result = $this->service->subscribeTenantToPlan($this->tenant, Plan::query()->where('slug', 'standard')->firstOrFail(), 'monthly', 'paystack');

    $this->paystackWebhook($this->paystackChargeSuccess($result['reference'], 100))->assertOk();

    $charge = PaymentTransaction::query()->where('reference', $result['reference'])->firstOrFail();
    expect($charge->status)->toBe('pending')->and($charge->meta['needs_review'])->toBe('amount_mismatch');
});

it('applies a coupon to the plan line and completes it after its cycles', function (): void {
    coupon(['code' => 'HALF', 'max_discount_amount' => 15]);

    $result = $this->service->subscribeTenantToPlan($this->tenant, Plan::query()->where('slug', 'standard')->firstOrFail(), 'monthly', 'paystack', 'half');
    $charge = PaymentTransaction::query()->where('reference', $result['reference'])->firstOrFail();

    // 50% of 39.00 = 19.50, capped at 15.00.
    expect((string) $charge->amount)->toBe('24.0000')
        ->and(collect($charge->line_items)->firstWhere('type', 'coupon_discount')['amount'])->toBe('-15.0000');

    $this->paystackWebhook($this->paystackChargeSuccess($result['reference'], 2400))->assertOk();

    $redemption = PlatformCouponRedemption::query()->firstOrFail();
    expect($redemption->status)->toBe('completed')->and((string) $redemption->total_discount_amount)->toBe('15.0000');
});

it('never exceeds a coupon usage limit and releases unused reservations', function (): void {
    $coupon = coupon(['code' => 'ONLYONE', 'usage_limit_total' => 1]);
    $other = $this->createTenant('b');

    $this->service->subscribeTenantToPlan($this->tenant, Plan::query()->where('slug', 'standard')->firstOrFail(), 'monthly', 'paystack', 'ONLYONE');

    expect(fn () => $this->service->subscribeTenantToPlan($other, Plan::query()->where('slug', 'standard')->firstOrFail(), 'monthly', 'paystack', 'ONLYONE'))
        ->toThrow(fn (ApiException $e) => expect($e->details['reason'])->toBe('usage_limit_reached'));

    $this->service->cancelTenantSubscription($this->tenant);

    expect($coupon->refresh()->times_redeemed)->toBe(0)
        ->and(PlatformCouponRedemption::query()->value('status'))->toBe('released');
});

it('renews through the saved authorization exactly once per period', function (): void {
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'success', 'id' => 555]])]);

    $subscription = $this->subscribe($this->tenant, 'standard', attributes: [
        'gateway' => 'paystack', 'authorization_reference' => 'AUTH_saved', 'renews_at' => now()->subHour(),
    ]);

    (new ProcessSubscriptionRenewal)->handle(...array_map(app(...), [
        SubscriptionService::class, SubscriptionBillingService::class,
        PlatformSettingsService::class, NotificationDispatchService::class,
    ]));
    $this->service->renew($subscription->refresh());

    expect(PaymentTransaction::query()->where('subscription_id', $subscription->id)->count())->toBe(1)
        ->and($subscription->refresh()->renews_at->isFuture())->toBeTrue();

    Http::assertSentCount(1);
});

it('moves a subscription to past due when a renewal is declined, restricting admin writes', function (): void {
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient funds']])]);

    $subscription = $this->subscribe($this->tenant, 'standard', attributes: [
        'gateway' => 'paystack', 'authorization_reference' => 'AUTH_saved', 'renews_at' => now()->subHour(),
    ]);

    $this->service->renew($subscription);

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue);

    $this->travel(8)->days();
    expect(app(SubscriptionAccessService::class)->restriction($this->tenant))->toBe('read_only');
});

it('ends a trial without payment method in past due', function (): void {
    $subscription = $this->service->createInitialSubscription($this->tenant, ($this->price)('basic'));
    $this->travel(8)->days();

    $this->service->renew($subscription->refresh());

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue);
});

it('refunds partially and never beyond the charge', function (): void {
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 8080, 'status' => 'pending']])]);

    $result = $this->service->subscribeTenantToPlan($this->tenant, Plan::query()->where('slug', 'standard')->firstOrFail(), 'monthly', 'paystack');
    $this->paystackWebhook($this->paystackChargeSuccess($result['reference'], 3900))->assertOk();
    $charge = PaymentTransaction::query()->where('reference', $result['reference'])->firstOrFail();
    $admin = PlatformUser::query()->create(['name' => 'Billing', 'email' => 'billing@platform.test', 'password' => 'Secret123']);

    $refund = $this->service->refundTransaction($charge, '10', 'Goodwill', $admin);

    expect($refund->status)->toBe('pending')->and((string) $refund->amount)->toBe('-10.0000')->and($refund->provider_reference)->toBe('8080');

    expect(fn () => $this->service->refundTransaction($charge, '29.01', 'Too much', $admin))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('refund_exceeds_payment'));

    $this->paystackWebhook(['event' => 'refund.processed', 'data' => [
        'id' => 8080, 'transaction_reference' => $charge->reference, 'amount' => 1000, 'currency' => 'USD', 'status' => 'processed',
    ]])->assertOk();

    expect($refund->refresh()->status)->toBe('successful')
        ->and($charge->refresh()->status)->toBe('successful');
});

it('schedules downgrades for the renewal and requires impact confirmation', function (): void {
    tenancy()->initialize($this->tenant);
    User::query()->create(['name' => 'A', 'email' => 'a@a.test', 'password' => 'Secret123', 'is_active' => true]);
    User::query()->create(['name' => 'B', 'email' => 'b@a.test', 'password' => 'Secret123', 'is_active' => true]);
    User::query()->create(['name' => 'C', 'email' => 'c@a.test', 'password' => 'Secret123', 'is_active' => true]);

    $subscription = $this->subscribe($this->tenant, 'standard', attributes: ['gateway' => 'paystack', 'authorization_reference' => 'AUTH_saved']);
    $basic = Plan::query()->where('slug', 'basic')->firstOrFail();

    expect(fn () => $this->service->swapTenantPlan($this->tenant, $basic))
        ->toThrow(fn (ApiException $e) => expect($e->errorCode)->toBe('plan_change_requires_confirmation')
            ->and($e->details['limits_exceeded'][0]['key'])->toBe('max_users'));

    $this->service->swapTenantPlan($this->tenant, $basic, null, true);

    expect($subscription->refresh()->plan_id)->not->toBe($basic->id)
        ->and($subscription->scheduled_plan_id)->toBe($basic->id);
});

it('charges an immediate prorated upgrade when proration is immediate', function (): void {
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'data' => ['status' => 'success', 'id' => 777]])]);
    app(PlatformSettingsService::class)->set('proration_mode', 'immediate');

    $subscription = $this->subscribe($this->tenant, 'standard', attributes: [
        'gateway' => 'paystack', 'authorization_reference' => 'AUTH_saved', 'starts_at' => now()->subDays(15), 'renews_at' => now()->addDays(15),
    ]);
    $premium = Plan::query()->where('slug', 'premium')->firstOrFail();

    $this->service->swapTenantPlan($this->tenant, $premium);

    $proration = PaymentTransaction::query()->where('subscription_id', $subscription->id)->firstOrFail();

    expect($subscription->refresh()->plan_id)->toBe($premium->id)
        ->and($proration->meta['purpose'])->toBe('proration')
        ->and((float) $proration->amount)->toBeGreaterThan(9.0)->toBeLessThan(11.0);
});

it('moves an awaiting-payment tenant to provisioning on its first payment', function (): void {
    Bus::fake([ProvisionTenantDatabase::class]);
    $this->tenant->forceFill(['status' => TenantStatus::AwaitingPayment])->save();
    $result = $this->service->subscribeTenantToPlan($this->tenant, Plan::query()->where('slug', 'standard')->firstOrFail(), 'monthly', 'paystack');

    $this->paystackWebhook($this->paystackChargeSuccess($result['reference'], 3900))->assertOk();

    expect($this->tenant->refresh()->status)->toBe(TenantStatus::Provisioning);
    Bus::assertDispatched(
        ProvisionTenantDatabase::class,
        fn ($job): bool => $job->tenantId === $this->tenant->id,
    );
});

it('records a live first paid charge and its MRR', function (): void {
    config(['app.payments_live_allowed' => true]);
    $this->platformGateway('paystack', 'live', attributes: ['secret_key' => self::PAYSTACK_TEST_SECRET, 'is_default' => false]);
    app(PlatformSettingsService::class)->set('billing_payment_mode', 'live');

    $result = $this->service->subscribeTenantToPlan($this->tenant, Plan::query()->where('slug', 'standard')->firstOrFail(), 'yearly', 'paystack');
    $this->paystackWebhook($this->paystackChargeSuccess($result['reference'], 38000, overrides: ['domain' => 'live']), 'live')->assertOk();

    $movement = SubscriptionMrrMovement::query()->firstOrFail();

    expect(PaymentTransaction::query()->where('reference', $result['reference'])->value('is_first_paid_charge'))->toBeTrue()
        ->and($movement->type)->toBe('new')
        ->and((string) $movement->mrr_after)->toBe('31.6666');
});
