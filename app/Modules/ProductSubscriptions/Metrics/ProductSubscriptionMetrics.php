<?php

declare(strict_types=1);

namespace App\Modules\ProductSubscriptions\Metrics;

use App\Modules\ProductSubscriptions\Models\CustomerSubscription;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use Illuminate\Support\Facades\DB;

/**
 * The product-subscriptions section (spec §44.3): subscriptions active,
 * paused and retrying a failed renewal now, and the recurring value per
 * month: each active subscription's base-currency price at today's
 * regular price, less its discount, spread over a month. It is an
 * estimate (tax, shipping and future price changes are left out).
 */
final readonly class ProductSubscriptionMetrics
{
    /** Periods per month of each interval (before interval_count). */
    private const array PER_MONTH = ['weekly' => '4.3333', 'biweekly' => '2.1667', 'monthly' => '1', 'quarterly' => '0.3333'];

    public function __construct(private TenantSettingsService $settings) {}

    public function productSubscriptions(DateRange $range, MetricsScope $scope): SectionResult
    {
        $currency = strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));

        return new SectionResult(
            kpis: [
                ...$this->contextual($range, $scope),
                KpiValue::money('recurring_value_per_month', 'Recurring value per month', $this->recurringPerMonth(), $currency, $range, null, KpiValue::UP_IS_GOOD, estimated: true),
            ],
        );
    }

    /**
     * The product-subscriptions list strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        $counts = DB::connection('tenant')->table('customer_subscriptions')->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            KpiValue::count('active', 'Active', (int) ($counts[CustomerSubscription::ACTIVE] ?? 0), $range, null, KpiValue::UP_IS_GOOD),
            KpiValue::count('paused', 'Paused', (int) ($counts[CustomerSubscription::PAUSED] ?? 0), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('failed_renewals', 'Failed renewals', (int) ($counts[CustomerSubscription::PAYMENT_FAILED] ?? 0), $range, null, KpiValue::DOWN_IS_GOOD),
        ];
    }

    private function recurringPerMonth(): string
    {
        $total = '0';

        DB::connection('tenant')->table('customer_subscriptions as s')
            ->join('product_subscription_plans as pl', 'pl.id', '=', 's.product_subscription_plan_id')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 's.product_variant_id')
            ->where('s.status', CustomerSubscription::ACTIVE)
            ->selectRaw('s.id, s.quantity, pl.interval, pl.interval_count, COALESCE(v.price, p.price) as price, p.subscription_discount_percent as percent')
            ->orderBy('s.id')
            ->chunk(500, function ($rows) use (&$total): void {
                foreach ($rows as $row) {
                    $net = bcmul(bcmul((string) $row->price, (string) $row->quantity, 8), bcsub('1', bcdiv((string) ($row->percent ?? '0'), '100', 8), 8), 8);
                    $perMonth = bcdiv(self::PER_MONTH[$row->interval] ?? '1', (string) max(1, (int) $row->interval_count), 8);
                    $total = bcadd($total, bcmul($net, $perMonth, 8), 8);
                }
            });

        return bcadd($total, '0', 4);
    }
}
