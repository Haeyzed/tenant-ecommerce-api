<?php

declare(strict_types=1);

use App\Modules\Access\Models\PlatformUser;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Dashboard\Jobs\RecordPlatformDailyMetrics;
use App\Modules\Dashboard\Models\PlatformDailyMetric;
use App\Modules\Notifications\Enums\NotificationScope;
use App\Modules\Notifications\Notifications\TemplatedNotification;
use App\Modules\Notifications\Services\NotificationTemplateService;
use App\Modules\Plans\Models\Plan;
use App\Modules\Settings\Services\PlatformSettingsService;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Database\Seeders\Landlord\PlatformAccessSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-25 08:00:00', 'UTC'));
    $this->seed(PlatformAccessSeeder::class);
    $this->seedPlans();

    $this->admin = PlatformUser::query()->create(['name' => 'Root', 'email' => 'root@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $this->admin->assignRole('super-admin');
    $this->auth = ['Authorization' => 'Bearer '.$this->admin->createToken('t', ['platform'])->plainTextToken];

    $this->basic = Plan::query()->where('slug', 'basic')->firstOrFail();
    $this->pro = Plan::query()->where('slug', '!=', 'basic')->orderByDesc('sort_order')->firstOrFail();
});

/**
 * A live subscription row for a landlord-only tenant.
 *
 * @param  array<string, mixed>  $attributes
 */
function dashboardSubscription(string $tenantId, Plan $plan, array $attributes = []): Subscription
{
    $price = $plan->prices()->where('billing_interval', 'monthly')->firstOrFail();

    /** @var Subscription */
    return Subscription::query()->create(array_merge([
        'tenant_id' => $tenantId,
        'plan_id' => $plan->id,
        'plan_price_id' => $price->id,
        'currency_code' => 'USD',
        'billing_interval' => 'monthly',
        'gateway_mode' => 'live',
        'status' => SubscriptionStatus::Active,
        'trial_days' => 0,
        'starts_at' => now()->subMonths(3),
        'renews_at' => now()->addDays(3),
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function dashboardTransaction(Subscription $subscription, string $amount, array $attributes = []): void
{
    DB::connection('landlord')->table('payment_transactions')->insert(array_merge([
        'tenant_id' => $subscription->tenant_id,
        'subscription_id' => $subscription->id,
        'type' => 'charge',
        'mode' => 'live',
        'provider' => 'paystack',
        'reference' => (string) Str::uuid(),
        'amount' => $amount,
        'currency_code' => 'USD',
        'status' => 'successful',
        'is_first_paid_charge' => false,
        'line_items' => null,
        'fee' => null,
        'paid_at' => now()->subDays(5),
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(5),
    ], $attributes));
}

function dashboardMovement(Subscription $subscription, string $type, string $delta, string $occurredAt, string $reason = 'renewal', ?int $planId = null): void
{
    DB::connection('landlord')->table('subscription_mrr_movements')->insert([
        'tenant_id' => $subscription->tenant_id,
        'subscription_id' => $subscription->id,
        'plan_id' => $planId ?? $subscription->plan_id,
        'type' => $type,
        'currency_code' => $subscription->currency_code,
        'mrr_before' => '0',
        'mrr_after' => '0',
        'mrr_delta' => $delta,
        'reason' => $reason,
        'occurred_at' => $occurredAt,
        'created_at' => $occurredAt,
    ]);
}

/**
 * @return array<string, mixed>
 */
function kpi(array $kpis, string $key, ?string $currency = null): array
{
    foreach ($kpis as $kpi) {
        if ($kpi['key'] === $key && ($currency === null || $kpi['currency_code'] === $currency)) {
            return $kpi;
        }
    }

    throw new RuntimeException("KPI [{$key}] not found.");
}

it('lists only the sections a viewer may see and refuses the others', function (): void {
    $this->landlordJson('GET', '/api/admin/dashboard', [], $this->auth)
        ->assertOk()
        ->assertJsonPath('data.sections.*.key', ['overview', 'tenants', 'subscriptions', 'revenue', 'plans', 'payments', 'affiliates', 'operations']);

    $support = PlatformUser::query()->create(['name' => 'Sam', 'email' => 'sam@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $support->assignRole('support-staff');
    $supportAuth = ['Authorization' => 'Bearer '.$support->createToken('t', ['platform'])->plainTextToken];

    $this->landlordJson('GET', '/api/admin/dashboard', [], $supportAuth)
        ->assertOk()->assertJsonPath('data.sections.*.key', ['overview', 'tenants']);

    $this->landlordJson('GET', '/api/admin/dashboard/revenue', [], $supportAuth)->assertForbidden();

    // The overview leaves out the parts the viewer may not see.
    $keys = array_column($this->landlordJson('GET', '/api/admin/dashboard/overview', [], $supportAuth)->assertOk()->json('data.kpis'), 'key');
    expect($keys)->toBe(['active_tenants']);

    $this->landlordJson('GET', '/api/admin/dashboard/nonsense', [], $this->auth)->assertNotFound();
});

it('reports revenue from live successful rows only, per currency, with like-for-like comparison', function (): void {
    $tenant = $this->createTenantRow('a');
    $sub = dashboardSubscription($tenant, $this->basic);

    dashboardTransaction($sub, '100.0000', ['fee' => '2.0000', 'line_items' => json_encode([
        ['type' => 'plan', 'key' => 'basic', 'label' => 'Basic', 'amount' => '120.0000'],
        ['type' => 'coupon_discount', 'key' => 'SAVE20', 'label' => 'Coupon', 'amount' => '-20.0000'],
    ])]);
    dashboardTransaction($sub, '50.0000', ['fee' => '1.0000', 'currency_code' => 'NGN']);
    dashboardTransaction($sub, '-30.0000', ['type' => 'refund']);
    dashboardTransaction($sub, '999.0000', ['mode' => 'test']);
    dashboardTransaction($sub, '999.0000', ['status' => 'failed', 'paid_at' => null]);
    // Previous period (2026-07-27 .. 2026-08-25): 40 USD.
    dashboardTransaction($sub, '40.0000', ['paid_at' => '2026-08-10 12:00:00', 'created_at' => '2026-08-10 12:00:00']);

    $data = $this->landlordJson('GET', '/api/admin/dashboard/revenue', [], $this->auth)->assertOk()
        ->assertJsonPath('data.range.from', '2026-08-26')
        ->assertJsonPath('data.range.to', '2026-09-24')
        ->assertJsonPath('data.comparison_range', ['from' => '2026-07-27', 'to' => '2026-08-25'])
        ->assertJsonPath('meta.cached', false)
        ->json('data');

    $gross = kpi($data['kpis'], 'gross_revenue', 'USD');
    expect($gross['value'])->toBe('100.0000')
        ->and($gross['format'])->toBe('money')
        ->and($gross['comparison']['value'])->toBe('40.0000')
        ->and($gross['comparison']['change_percent'])->toBe('150.0')
        ->and($gross['comparison']['direction'])->toBe('up')
        ->and($gross['comparison']['sentiment'])->toBe('positive')
        ->and($gross['supporting_label'])->toBe('vs previous 30 days');

    expect(kpi($data['kpis'], 'gross_revenue', 'NGN')['value'])->toBe('50.0000')
        ->and(kpi($data['kpis'], 'gross_revenue', 'NGN')['comparison']['change_percent'])->toBeNull()
        ->and(kpi($data['kpis'], 'discounts', 'USD')['value'])->toBe('20.0000')
        ->and(kpi($data['kpis'], 'refunds', 'USD')['value'])->toBe('30.0000')
        ->and(kpi($data['kpis'], 'refunds', 'USD')['comparison']['sentiment'])->toBe('negative')
        ->and(kpi($data['kpis'], 'net_revenue', 'USD')['value'])->toBe('70.0000')
        ->and(kpi($data['kpis'], 'net_collected_revenue', 'USD')['value'])->toBe('68.0000');

    // Without reporting rates there is no combined (estimated) total.
    expect(collect($data['kpis'])->where('key', 'gross_revenue_combined'))->toBeEmpty();

    $this->landlordJson('GET', '/api/admin/dashboard/revenue', [], $this->auth)->assertOk()->assertJsonPath('meta.cached', true);
});

it('adds an estimated combined total when reporting rates cover every currency', function (): void {
    app(PlatformSettingsService::class)->set('reporting_exchange_rates', ['NGN' => 0.001]);

    $sub = dashboardSubscription($this->createTenantRow('a'), $this->basic);
    dashboardTransaction($sub, '100.0000');
    dashboardTransaction($sub, '50000.0000', ['currency_code' => 'NGN']);

    $combined = kpi($this->landlordJson('GET', '/api/admin/dashboard/revenue?compare=none', [], $this->auth)->json('data.kpis'), 'gross_revenue_combined');

    expect($combined['value'])->toBe('150.0000')
        ->and($combined['currency_code'])->toBe('USD')
        ->and($combined['is_estimated'])->toBeTrue()
        ->and($combined['comparison'])->toBeNull();
});

it('derives MRR, paying tenants, churn and plan changes from the movement ledger', function (): void {
    $a = dashboardSubscription($this->createTenantRow('a'), $this->basic);
    $b = dashboardSubscription($this->createTenantRow('b'), $this->basic);

    dashboardMovement($a, 'new', '10.0000', '2026-06-01 10:00:00');
    dashboardMovement($b, 'new', '10.0000', '2026-06-02 10:00:00');
    // Tenant A upgrades inside the range; tenant B churns.
    dashboardMovement($a, 'expansion', '20.0000', '2026-09-10 10:00:00', 'plan_change', $this->pro->id);
    dashboardMovement($b, 'churn', '-10.0000', '2026-09-12 10:00:00', 'cancellation_effective');

    $overview = $this->landlordJson('GET', '/api/admin/dashboard/overview', [], $this->auth)->assertOk()->json('data');

    expect(kpi($overview['kpis'], 'paying_tenants')['value'])->toBe(1)
        ->and(kpi($overview['kpis'], 'paying_tenants')['comparison']['value'])->toBe(2)
        ->and(kpi($overview['kpis'], 'mrr', 'USD')['value'])->toBe('30.0000')
        ->and(kpi($overview['kpis'], 'mrr', 'USD')['comparison']['value'])->toBe('20.0000')
        ->and(kpi($overview['kpis'], 'net_new_mrr', 'USD')['value'])->toBe('10.0000');

    $mrrChart = collect($overview['charts'])->firstWhere('key', 'mrr_over_time');
    expect($mrrChart['series'][0]['points'][0])->toBe(['x' => '2026-08-26', 'y' => '20.0000'])
        ->and(end($mrrChart['series'][0]['points']))->toBe(['x' => '2026-09-24', 'y' => '30.0000']);

    $tenants = $this->landlordJson('GET', '/api/admin/dashboard/tenants', [], $this->auth)->json('data');
    expect(kpi($tenants['kpis'], 'logo_churn_rate')['value'])->toBe('50.0');

    $plans = $this->landlordJson('GET', '/api/admin/dashboard/plans', [], $this->auth)->json('data');
    expect(kpi($plans['kpis'], 'upgrades')['value'])->toBe(1)
        ->and(collect($plans['tables'])->firstWhere('key', 'plan_movement_matrix')['rows'])
        ->toBe([['from_plan' => $this->basic->name, 'to_plan' => $this->pro->name, 'changes' => 1]]);

    $revenue = $this->landlordJson('GET', '/api/admin/dashboard/revenue', [], $this->auth)->json('data');
    expect(kpi($revenue['kpis'], 'arr', 'USD')['value'])->toBe('360.0000')
        ->and(kpi($revenue['kpis'], 'arpa', 'USD')['value'])->toBe('30.0000');
});

it('measures trial conversion within the grace period', function (): void {
    $converted = dashboardSubscription($this->createTenantRow('a'), $this->basic, ['trial_ends_at' => '2026-09-01 00:00:00']);
    dashboardSubscription($this->createTenantRow('b'), $this->basic, ['trial_ends_at' => '2026-09-02 00:00:00']);
    // Outside the range: not counted at all.
    dashboardSubscription($this->createTenantRow('c'), $this->basic, ['trial_ends_at' => '2026-07-01 00:00:00']);

    dashboardTransaction($converted, '10.0000', ['is_first_paid_charge' => true, 'paid_at' => '2026-09-03 00:00:00']);

    $kpis = $this->landlordJson('GET', '/api/admin/dashboard/subscriptions', [], $this->auth)->assertOk()->json('data.kpis');

    expect(kpi($kpis, 'trial_conversion_rate')['value'])->toBe('50.0')
        ->and(kpi($kpis, 'trial_conversion_rate')['format'])->toBe('percent')
        ->and(kpi($kpis, 'active_subscriptions')['value'])->toBe(3)
        ->and(kpi($kpis, 'active_subscriptions')['comparison'])->toBeNull();
});

it('buckets by the platform timezone', function (): void {
    app(PlatformSettingsService::class)->set('default_timezone', 'Africa/Lagos');
    $sub = dashboardSubscription($this->createTenantRow('a'), $this->basic);

    // 23:30 UTC on the 20th is 00:30 on the 21st in Lagos (UTC+1).
    dashboardTransaction($sub, '10.0000', ['paid_at' => '2026-09-20 23:30:00']);

    $chart = collect($this->landlordJson('GET', '/api/admin/dashboard/revenue?range=custom&from=2026-09-20&to=2026-09-21&interval=day', [], $this->auth)
        ->assertOk()->json('data.charts'))->firstWhere('key', 'revenue_over_time');

    expect($chart['series'][0]['points'])->toBe([['x' => '2026-09-20', 'y' => '0.0000'], ['x' => '2026-09-21', 'y' => '10.0000']]);
});

it('validates the range parameters', function (): void {
    $this->landlordJson('GET', '/api/admin/dashboard/revenue?range=custom&from=2020-01-01&to=2026-01-01', [], $this->auth)->assertStatus(422)->assertJsonValidationErrors('to');
    $this->landlordJson('GET', '/api/admin/dashboard/revenue?range=custom', [], $this->auth)->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);
    $this->landlordJson('GET', '/api/admin/dashboard/revenue?range=forever', [], $this->auth)->assertStatus(422);
    $this->landlordJson('GET', '/api/admin/dashboard/revenue?from=2026-01-01', [], $this->auth)->assertStatus(422);
});

it('serves the list-screen KPI strips', function (): void {
    $a = $this->createTenantRow('a');
    Tenant::query()->whereKey($this->createTenantRow('b'))->update(['status' => TenantStatus::Suspended->value]);
    $sub = dashboardSubscription($a, $this->basic);
    dashboardTransaction($sub, '10.0000');
    dashboardTransaction($sub, '10.0000', ['status' => 'failed', 'paid_at' => null]);

    $tenants = $this->landlordJson('GET', '/api/admin/tenants/metrics', [], $this->auth)->assertOk()->json('data.kpis');
    expect(kpi($tenants, 'total')['value'])->toBe(2)->and(kpi($tenants, 'suspended')['value'])->toBe(1);

    $payments = $this->landlordJson('GET', '/api/admin/payment-transactions/metrics', [], $this->auth)->assertOk()->json('data.kpis');
    expect(kpi($payments, 'payment_success_rate')['value'])->toBe('50.0');

    $this->landlordJson('GET', '/api/admin/subscriptions/metrics', [], $this->auth)->assertOk()->assertJsonPath('data.kpis.0.key', 'active');
    $this->landlordJson('GET', '/api/admin/platform-coupons/metrics', [], $this->auth)->assertOk()->assertJsonPath('data.kpis.0.key', 'active_coupons');
});

it('records the previous day\'s stock metrics once and alerts billing admins about failed charges', function (): void {
    app(NotificationTemplateService::class)->seedDefaults(NotificationScope::Landlord);
    $billing = PlatformUser::query()->create(['name' => 'Bea', 'email' => 'bea@platform.test', 'password' => 'Secret123', 'is_active' => true]);
    $billing->assignRole('billing-admin');

    $sub = dashboardSubscription($this->createTenantRow('a'), $this->basic);
    dashboardTransaction($sub, '25.0000', ['status' => 'failed', 'paid_at' => null, 'created_at' => '2026-09-24 10:00:00']);

    RecordPlatformDailyMetrics::dispatchSync();
    RecordPlatformDailyMetrics::dispatchSync();

    expect(PlatformDailyMetric::valuesOn('tenants_by_status', CarbonImmutable::parse('2026-09-24')))
        ->toMatchArray(['active' => '1.0000', 'suspended' => '0.0000'])
        ->and(PlatformDailyMetric::valuesOn('subscriptions_by_status', CarbonImmutable::parse('2026-09-24'))['active'])->toBe('1.0000');

    Notification::assertSentToTimes($billing, TemplatedNotification::class, 1);
    Notification::assertNotSentTo($this->admin, TemplatedNotification::class);

    // The snapshot now gives stock KPIs a comparison.
    $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'));
    $kpis = $this->landlordJson('GET', '/api/admin/dashboard/tenants?range=today', [], $this->auth)->json('data.kpis');
    expect(kpi($kpis, 'active_tenants')['comparison']['value'])->toBe(1);
});

it('shows operations health and surfaces critical alerts on the overview', function (): void {
    Tenant::query()->whereKey($this->createTenantRow('a'))->update(['status' => TenantStatus::ProvisioningFailed->value]);
    DB::connection('landlord')->table('tenant_usage_snapshots')->insert([
        'tenant_id' => $this->createTenantRow('b'), 'date' => '2026-09-24', 'usage' => json_encode(['max_storage_mb' => 512]),
        'orders_count' => 3, 'gross_sales' => '10', 'base_currency' => 'USD', 'webhook_failures' => 2, 'failed_postings' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ops = $this->landlordJson('GET', '/api/admin/dashboard/operations', [], $this->auth)->assertOk()->json('data');

    expect(kpi($ops['kpis'], 'failed_provisioning')['value'])->toBe(1)
        ->and(kpi($ops['kpis'], 'storage_used_mb')['value'])->toBe(512)
        ->and(kpi($ops['kpis'], 'tenant_webhook_failures')['value'])->toBe(2)
        ->and(array_column($ops['alerts'], 'key'))->toContain('provisioning_failed');

    $overviewAlerts = $this->landlordJson('GET', '/api/admin/dashboard/overview', [], $this->auth)->json('data.alerts');
    expect(array_column($overviewAlerts, 'key'))->toBe(['provisioning_failed']);

    $tenants = $this->landlordJson('GET', '/api/admin/dashboard/tenants', [], $this->auth)->json('data.tables');
    expect(collect($tenants)->firstWhere('key', 'top_tenants_by_storage')['rows'][0]['storage_mb'])->toBe(512)
        ->and(collect($tenants)->firstWhere('key', 'top_tenants_by_orders')['rows'][0]['orders'])->toBe(3);
});
