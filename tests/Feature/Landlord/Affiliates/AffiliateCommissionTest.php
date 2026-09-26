<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Affiliates\Jobs\ApproveEligibleAffiliateCommissions;
use App\Modules\Affiliates\Models\Affiliate;
use App\Modules\Affiliates\Models\AffiliateCommission;
use App\Modules\Affiliates\Models\AffiliatePayout;
use App\Modules\Affiliates\Models\AffiliateReferral;
use App\Modules\Affiliates\Services\AffiliateCommissionService;
use App\Modules\Affiliates\Services\AffiliatePayoutService;
use App\Modules\Billing\Models\PaymentTransaction;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Plans\Models\Plan;
use App\Modules\Settings\Services\PlatformSettingsService;
use Carbon\CarbonImmutable;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC'));
    $this->seed(PlatformAccessSeeder::class);
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Landlord);
    $this->seedPlans();

    config(['app.payments_live_allowed' => true]);
    $this->platformGateway('paystack', 'test');
    $this->platformGateway('paystack', 'live', attributes: ['secret_key' => self::PAYSTACK_TEST_SECRET, 'is_default' => false]);
    $settings = app(PlatformSettingsService::class);
    $settings->set('billing_payment_mode', 'live');
    $settings->set('affiliate_program_enabled', true);

    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/abc', 'access_code' => 'abc']]),
        'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['id' => 8080, 'status' => 'pending']]),
    ]);

    $this->tenant = $this->createTenant('a');
    $this->affiliate = new Affiliate(['name' => 'Ann', 'email' => 'ann@promo.test', 'password' => 'Secret123', 'promotion_methods' => 'Blog']);
    $this->affiliate->forceFill([
        'public_id' => (string) Str::uuid(), 'status' => Affiliate::APPROVED, 'referral_code' => 'ANN2026', 'email_verified_at' => now(),
        'payout_method' => 'bank_transfer', 'payout_details' => ['account_name' => 'Ann', 'account_number' => '0123456789', 'bank_name' => 'Bank'],
        'payout_details_updated_at' => now()->subDays(10),
    ])->save();
    $this->referral = AffiliateReferral::query()->create([
        'affiliate_id' => $this->affiliate->id, 'tenant_id' => $this->tenant->id, 'source' => 'link', 'status' => 'registered',
        'attributed_at' => now()->subDay(), 'conversion_deadline' => now()->addDays(180),
    ]);

    $this->manager = PlatformUser::query()->create(['name' => 'Mia', 'email' => 'mia@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->manager->assignRole('affiliate-manager');
    $this->billing = PlatformUser::query()->create(['name' => 'Bea', 'email' => 'bea@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->billing->assignRole('billing-admin');
});

/**
 * Subscribes the tenant in live mode and completes the charge through a
 * signed webhook; returns the charge.
 */
function payStandard(int $amountMinor = 3900): PaymentTransaction
{
    $result = app(SubscriptionService::class)->subscribeTenantToPlan(test()->tenant, Plan::query()->where('slug', 'standard')->firstOrFail(), 'monthly', 'paystack');
    $payload = test()->paystackChargeSuccess($result['reference'], $amountMinor, overrides: ['domain' => 'live']);
    test()->paystackWebhook($payload, 'live')->assertOk();

    return PaymentTransaction::query()->where('reference', $result['reference'])->firstOrFail();
}

function refundCharge(PaymentTransaction $charge, string $amount): void
{
    $admin = PlatformUser::query()->firstOrCreate(['email' => 'refunds@platform.test'], ['name' => 'Refunds', 'password' => 'Secret123']);
    app(SubscriptionService::class)->refundTransaction($charge, $amount, 'Customer request', $admin);

    test()->paystackWebhook(['event' => 'refund.processed', 'data' => [
        'id' => 8080, 'transaction_reference' => $charge->reference, 'amount' => (int) round((float) $amount * 100), 'currency' => 'USD',
        'status' => 'processed', 'domain' => 'live',
    ]], 'live')->assertOk();
}

it('creates exactly one pending commission on the first paid live charge at the snapshotted rate', function (): void {
    $charge = payStandard();

    $commission = AffiliateCommission::query()->sole();
    expect($commission->status)->toBe('pending')
        ->and((string) $commission->base_amount)->toBe('39.0000')
        ->and((string) $commission->commission_rate_applied)->toBe('20.0000')
        ->and((string) $commission->amount)->toBe('7.8000')
        ->and($commission->hold_until->toDateString())->toBe('2026-10-10')
        ->and($this->referral->refresh()->status)->toBe('converted');

    // A replayed webhook, a later renewal and a default-rate change create nothing new.
    $this->paystackWebhook($this->paystackChargeSuccess($charge->reference, 3900, overrides: ['domain' => 'live']), 'live')->assertOk();
    app(PlatformSettingsService::class)->set('affiliate_default_commission_rate', '50');
    $this->travel(32)->days();
    app(SubscriptionService::class)->renew($charge->subscription->refresh());

    expect(AffiliateCommission::query()->count())->toBe(1)
        ->and((string) AffiliateCommission::query()->value('commission_rate_applied'))->toBe('20.0000');
});

it('uses the affiliate override rate', function (): void {
    $this->affiliate->forceFill(['commission_rate' => '12.5'])->save();

    payStandard();

    expect((string) AffiliateCommission::query()->value('commission_rate_applied'))->toBe('12.5000')
        ->and((string) AffiliateCommission::query()->value('amount'))->toBe('4.8800');
});

it('never pays commission on test-mode charges', function (): void {
    app(PlatformSettingsService::class)->set('billing_payment_mode', 'test');

    $result = app(SubscriptionService::class)->subscribeTenantToPlan($this->tenant, Plan::query()->where('slug', 'standard')->firstOrFail(), 'monthly', 'paystack');
    $this->paystackWebhook($this->paystackChargeSuccess($result['reference'], 3900))->assertOk();

    expect(PaymentTransaction::query()->where('reference', $result['reference'])->value('status'))->toBe('successful')
        ->and(AffiliateCommission::query()->count())->toBe(0)
        ->and($this->referral->refresh()->status)->toBe('registered');
});

it('settles obligations while the programme is off but not for a suspended affiliate', function (): void {
    app(PlatformSettingsService::class)->set('affiliate_program_enabled', false);
    $this->affiliate->forceFill(['status' => Affiliate::SUSPENDED])->save();

    payStandard();

    expect(AffiliateCommission::query()->count())->toBe(0)
        ->and($this->referral->refresh()->status)->toBe('ineligible')
        ->and($this->referral->ineligible_reason)->toBe('affiliate_not_active');
});

it('approves only after the hold and only with a note while under review', function (): void {
    payStandard();
    $commission = AffiliateCommission::query()->sole();
    $auth = ['Authorization' => 'Bearer '.$this->manager->createToken('t', ['platform'])->plainTextToken];

    $this->landlordJson('POST', "/api/admin/affiliate-commissions/{$commission->id}/approve", [], $auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'commission_on_hold');

    $this->travel(31)->days();
    // Tokens expire after 30 days; sign in again.
    $auth = ['Authorization' => 'Bearer '.$this->manager->createToken('t', ['platform'])->plainTextToken];
    app(AffiliateCommissionService::class)->handleDisputeOpened($commission->paymentTransaction);

    // Automatic approval skips a commission under review.
    app(PlatformSettingsService::class)->set('affiliate_commission_approval', 'automatic');
    ApproveEligibleAffiliateCommissions::dispatchSync();
    expect($commission->refresh()->status)->toBe('pending');

    $this->landlordJson('POST', "/api/admin/affiliate-commissions/{$commission->id}/approve", [], $auth)
        ->assertStatus(422)->assertJsonPath('meta.error_code', 'review_note_required');
    $this->landlordJson('POST', "/api/admin/affiliate-commissions/{$commission->id}/approve", ['note' => 'Dispute withdrawn'], $auth)
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.is_payable', true);

    // The billing admin cannot approve (separation of duties).
    $billingAuth = ['Authorization' => 'Bearer '.$this->billing->createToken('t', ['platform'])->plainTextToken];
    $this->landlordJson('POST', "/api/admin/affiliate-commissions/{$commission->id}/reject", ['reason' => 'x'], $billingAuth)->assertForbidden();
});

it('reverses a pending commission on a full refund and blocks later qualification', function (): void {
    $charge = payStandard();
    refundCharge($charge, '39');

    $commission = AffiliateCommission::query()->sole();
    expect($commission->status)->toBe('reversed')
        ->and($commission->reversal_reason)->toBe('refund')
        ->and((string) $commission->original_amount)->toBe('7.8000')
        ->and($this->referral->refresh()->ineligible_reason)->toBe('first_payment_refunded');
});

it('reduces an unpaid commission on a partial refund', function (): void {
    $charge = payStandard();
    refundCharge($charge, '13');

    $commission = AffiliateCommission::query()->sole();
    expect($commission->status)->toBe('pending')
        ->and((string) $commission->original_amount)->toBe('7.8000')
        ->and((string) $commission->amount)->toBe('5.2000');
});

it('pays out above the threshold, rolls smaller balances forward and claws back after payment', function (): void {
    app(PlatformSettingsService::class)->set('affiliate_minimum_payout', ['USD' => 5]);
    $charge = payStandard();
    $commission = AffiliateCommission::query()->sole();
    $this->travel(31)->days();
    app(AffiliateCommissionService::class)->approve($commission, $this->manager);

    $payouts = app(AffiliatePayoutService::class);
    $created = $payouts->generate(CarbonImmutable::parse('2026-10-31'));
    expect($created)->toHaveCount(1)
        ->and((string) $created->first()->amount)->toBe('7.8000')
        ->and($created->first()->reference)->toBe(sprintf('AFP-202610-%06d-USD', $this->affiliate->id))
        ->and($payouts->generate(CarbonImmutable::parse('2026-10-31')))->toHaveCount(0);

    $payout = $created->first();
    $billingAuth = ['Authorization' => 'Bearer '.$this->billing->createToken('t', ['platform'])->plainTextToken];
    $managerAuth = ['Authorization' => 'Bearer '.$this->manager->createToken('t', ['platform'])->plainTextToken];

    $this->landlordJson('POST', "/api/admin/affiliate-payouts/{$payout->id}/mark-paid", ['external_reference' => 'TRF-1'], $managerAuth)->assertForbidden();
    $this->landlordJson('GET', "/api/admin/affiliate-payouts/{$payout->id}", [], $managerAuth)->assertOk()->assertJsonMissingPath('data.payout_details');
    $this->landlordJson('GET', "/api/admin/affiliate-payouts/{$payout->id}", [], $billingAuth)->assertOk()->assertJsonPath('data.payout_details.account_number', '0123456789');

    $headers = $billingAuth + ['Idempotency-Key' => (string) Str::uuid()];
    $this->landlordJson('POST', "/api/admin/affiliate-payouts/{$payout->id}/mark-paid", ['external_reference' => 'TRF-1'], $headers)->assertOk()->assertJsonPath('data.status', 'paid');
    $this->landlordJson('POST', "/api/admin/affiliate-payouts/{$payout->id}/mark-paid", ['external_reference' => 'TRF-2'], $billingAuth + ['Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(409)->assertJsonPath('meta.error_code', 'state_conflict');
    expect($commission->refresh()->status)->toBe('paid');

    // A later full refund claws the paid commission back as a negative payable row.
    refundCharge($charge, '39');
    $clawback = AffiliateCommission::query()->where('type', 'clawback')->sole();
    expect((string) $clawback->amount)->toBe('-7.8000')
        ->and($clawback->status)->toBe('approved')
        ->and($clawback->reverses_commission_id)->toBe($commission->id);

    // A negative balance is never paid out; it rolls forward.
    expect($payouts->generate(CarbonImmutable::parse('2026-11-30')))->toHaveCount(0);
    expect(app(AffiliateCommissionService::class)->balances($this->affiliate)['USD']['payable'])->toBe('-7.8000');
});

it('holds payouts after payout details change and detaches rows of failed or cancelled payouts', function (): void {
    app(PlatformSettingsService::class)->set('affiliate_minimum_payout', ['USD' => 5]);
    payStandard();
    $commission = AffiliateCommission::query()->sole();
    $this->travel(31)->days();
    app(AffiliateCommissionService::class)->approve($commission, $this->manager);
    $payouts = app(AffiliatePayoutService::class);

    $this->affiliate->forceFill(['payout_details_updated_at' => now()->subHours(10)])->save();
    expect($payouts->generate(CarbonImmutable::parse('2026-10-31')))->toHaveCount(0);

    $this->affiliate->forceFill(['payout_details_updated_at' => now()->subDays(4)])->save();
    $payout = $payouts->generate(CarbonImmutable::parse('2026-10-31'))->sole();

    $payouts->markFailed($payout, 'Account closed', $this->billing);
    expect($commission->refresh()->affiliate_payout_id)->toBeNull()
        ->and($commission->status)->toBe('approved');

    $next = $payouts->generate(CarbonImmutable::parse('2026-11-30'))->sole();
    $payouts->cancel($next, $this->billing);
    expect($next->refresh()->status)->toBe(AffiliatePayout::CANCELLED)
        ->and($commission->refresh()->affiliate_payout_id)->toBeNull();
});

it('shows the affiliate its own dashboard and the platform its affiliate section', function (): void {
    payStandard();
    $auth = ['Authorization' => 'Bearer '.$this->affiliate->createToken('t', ['affiliate'])->plainTextToken];

    $kpis = collect($this->landlordJson('GET', '/api/affiliate/dashboard?range=this_month', [], $auth)->assertOk()->json('data.kpis'))->keyBy('key');
    expect($kpis['paid_conversions']['value'])->toBe(1)
        ->and($kpis['pending_commission']['value'])->toBe('7.8000')
        ->and($kpis['conversion_rate']['value'])->toBe('100.0');

    $this->landlordJson('GET', '/api/affiliate/commissions', [], $auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.tenant_id');

    $managerAuth = ['Authorization' => 'Bearer '.$this->manager->createToken('t', ['platform'])->plainTextToken];
    $section = $this->landlordJson('GET', '/api/admin/dashboard/affiliates?range=this_month', [], $managerAuth)->assertOk()->json('data');
    expect(collect($section['kpis'])->firstWhere('key', 'affiliate_attributed_revenue')['value'])->toBe('39.0000');

    $this->landlordJson('GET', '/api/admin/affiliate-commissions/metrics', [], $managerAuth)->assertOk();
    $this->landlordJson('GET', '/api/admin/affiliates/metrics', [], $managerAuth)->assertOk()->assertJsonPath('data.kpis.0.value', 1);
});
