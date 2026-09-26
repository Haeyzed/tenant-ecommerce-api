<?php

declare(strict_types=1);

namespace App\Modules\Billing\Metrics;

use App\Modules\Settings\Services\PlatformSettingsService;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\TimeSeries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The platform-coupons list KPI strip (spec §22.4, §14.8). The discount
 * given is the revenue section's discounts figure, never recomputed.
 */
final readonly class CouponMetrics
{
    public function __construct(
        private RevenueMetrics $revenue,
        private PlatformSettingsService $settings,
    ) {}

    /**
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range): array
    {
        $comparison = $range->comparison();
        $redemptions = DB::connection('landlord')->table('platform_coupon_redemptions')->whereNotNull('activated_at');

        return [
            KpiValue::count('active_coupons', 'Active coupons', $this->active()->count(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('redemptions', 'Redemptions', (int) (TimeSeries::total($redemptions, 'activated_at', $range, 'COUNT(*)')[''] ?? 0), $range,
                $comparison === null ? null : (int) (TimeSeries::total($redemptions, 'activated_at', $comparison, 'COUNT(*)')[''] ?? 0)),
            ...$this->settings->reportingTotals()->kpis('discount_given', 'Discount given', $this->revenue->discounts($range), $range,
                $comparison === null ? null : $this->revenue->discounts($comparison), KpiValue::NEUTRAL),
            KpiValue::count('expiring_soon', 'Expiring in 7 days', $this->active()->whereBetween('ends_at', [now(), now()->addDays(7)])->count(), $range, null, KpiValue::NEUTRAL),
        ];
    }

    private function active(): Builder
    {
        return DB::connection('landlord')->table('platform_coupons')
            ->where('is_active', true)
            ->where(static fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(static fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }
}
