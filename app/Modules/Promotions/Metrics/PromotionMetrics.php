<?php

declare(strict_types=1);

namespace App\Modules\Promotions\Metrics;

use App\Modules\Promotions\Models\PromotionRedemption;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use App\Shared\Metrics\TimeSeries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Promotion figures (spec §44.2 "Promotions"): running, scheduled and ended
 * by window now (the day and time filters are not applied); committed
 * redemptions of live orders by committed_at; discount given is
 * Σ base_discount_amount (§37.5).
 */
final readonly class PromotionMetrics
{
    public function __construct(private TenantSettingsService $settings) {}

    public function promotions(DateRange $range, MetricsScope $scope): SectionResult
    {
        $windows = $this->windows();
        $count = TimeSeries::aggregate($this->redemptions($scope), 'r.committed_at', $range, 'COUNT(*)')[''] ?? array_fill_keys($range->buckets(), '0');
        $discount = TimeSeries::aggregate($this->redemptions($scope), 'r.committed_at', $range, 'SUM(r.base_discount_amount)')[''] ?? array_fill_keys($range->buckets(), '0');
        $previous = ($comparison = $range->comparison()) === null ? null : $this->flows($comparison, $scope);
        $currency = $this->currency();

        return new SectionResult(
            kpis: [
                KpiValue::count('running_promotions', 'Running promotions', $windows['running'], $range, null, KpiValue::NEUTRAL),
                KpiValue::count('active_coupons', 'Active coupons', $this->activeCoupons(), $range, null, KpiValue::NEUTRAL),
                KpiValue::count('redemptions', 'Redemptions', (int) TimeSeries::sum($count), $range, $previous['redemptions'] ?? null, KpiValue::UP_IS_GOOD, TimeSeries::sparkline($count, true)),
                KpiValue::money('discount_given', 'Discount given', TimeSeries::sum($discount), $currency, $range, $previous['discount'] ?? null, KpiValue::NEUTRAL, TimeSeries::sparkline($discount)),
            ],
            charts: [new ChartSeries('discount_over_time', 'Discount given', ChartSeries::BAR, KpiValue::MONEY,
                [['key' => 'discount', 'label' => 'Discount', 'points' => TimeSeries::points($discount)]], $range->interval, $currency)],
            tables: [$this->topPromotions($range, $scope, $currency)],
        );
    }

    /**
     * The promotions list KPI strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function promotionsStrip(DateRange $range, MetricsScope $scope): array
    {
        $windows = $this->windows();
        $flows = $this->flows($range, $scope);
        $previous = ($comparison = $range->comparison()) === null ? null : $this->flows($comparison, $scope);

        return [
            KpiValue::count('running_promotions', 'Running', $windows['running'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('scheduled_promotions', 'Scheduled', $windows['scheduled'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('ended_promotions', 'Ended', $windows['ended'], $range, null, KpiValue::NEUTRAL),
            KpiValue::count('redemptions', 'Redemptions', $flows['redemptions'], $range, $previous['redemptions'] ?? null),
            KpiValue::money('discount_given', 'Discount given', $flows['discount'], $this->currency(), $range, $previous['discount'] ?? null, KpiValue::NEUTRAL),
        ];
    }

    /**
     * The coupons list KPI strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function couponsStrip(DateRange $range, MetricsScope $scope): array
    {
        $coupon = static fn (Builder $q): Builder => $q->whereNotNull('r.coupon_id');
        $flows = $this->flows($range, $scope, $coupon);
        $previous = ($comparison = $range->comparison()) === null ? null : $this->flows($comparison, $scope, $coupon);
        $row = DB::connection('tenant')->table('coupons as c')
            ->selectRaw('COUNT(*) as codes, SUM(CASE WHEN (c.expires_at IS NOT NULL AND c.expires_at <= ?) OR (c.usage_limit IS NOT NULL AND c.times_redeemed >= c.usage_limit) THEN 1 ELSE 0 END) as finished', [now()])
            ->first();

        return [
            KpiValue::count('coupon_codes', 'Codes', (int) ($row->codes ?? 0), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('active_coupons', 'Active', $this->activeCoupons(), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('expired_or_exhausted_coupons', 'Expired or exhausted', (int) ($row->finished ?? 0), $range, null, KpiValue::NEUTRAL),
            KpiValue::count('redemptions', 'Redemptions', $flows['redemptions'], $range, $previous['redemptions'] ?? null),
            KpiValue::money('discount_given', 'Discount given', $flows['discount'], $this->currency(), $range, $previous['discount'] ?? null, KpiValue::NEUTRAL),
        ];
    }

    /**
     * @return array{running: int, scheduled: int, ended: int}
     */
    private function windows(): array
    {
        $now = now();
        $row = DB::connection('tenant')->table('promotions')->whereNull('deleted_at')
            ->selectRaw('SUM(CASE WHEN is_active = 1 AND (starts_at IS NULL OR starts_at <= ?) AND (ends_at IS NULL OR ends_at > ?) THEN 1 ELSE 0 END) as running,'
                .' SUM(CASE WHEN is_active = 1 AND starts_at > ? THEN 1 ELSE 0 END) as scheduled,'
                .' SUM(CASE WHEN ends_at IS NOT NULL AND ends_at <= ? THEN 1 ELSE 0 END) as ended', [$now, $now, $now, $now])
            ->first();

        return ['running' => (int) ($row->running ?? 0), 'scheduled' => (int) ($row->scheduled ?? 0), 'ended' => (int) ($row->ended ?? 0)];
    }

    /**
     * Active codes of running promotions, not expired or used up.
     */
    private function activeCoupons(): int
    {
        $now = now();

        return DB::connection('tenant')->table('coupons as c')
            ->join('promotions as p', 'p.id', '=', 'c.promotion_id')
            ->whereNull('p.deleted_at')->where('p.is_active', true)->where('c.is_active', true)
            ->where(static fn (Builder $q) => $q->whereNull('p.starts_at')->orWhere('p.starts_at', '<=', $now))
            ->where(static fn (Builder $q) => $q->whereNull('p.ends_at')->orWhere('p.ends_at', '>', $now))
            ->where(static fn (Builder $q) => $q->whereNull('c.expires_at')->orWhere('c.expires_at', '>', $now))
            ->where(static fn (Builder $q) => $q->whereNull('c.usage_limit')->orWhereColumn('c.times_redeemed', '<', 'c.usage_limit'))
            ->count();
    }

    /**
     * @param  (\Closure(Builder): Builder)|null  $narrow
     * @return array{redemptions: int, discount: string}
     */
    private function flows(DateRange $range, MetricsScope $scope, ?\Closure $narrow = null): array
    {
        $query = $this->redemptions($scope);
        $query = $narrow === null ? $query : $narrow($query);

        $row = $query->whereBetween('r.committed_at', [$range->startUtc(), $range->endUtc()])
            ->selectRaw('COUNT(*) as redemptions, SUM(r.base_discount_amount) as discount')->first();

        return ['redemptions' => (int) ($row->redemptions ?? 0), 'discount' => bcadd((string) ($row->discount ?? '0'), '0', 4)];
    }

    private function topPromotions(DateRange $range, MetricsScope $scope, string $currency): TableBlock
    {
        $rows = $this->redemptions($scope)
            ->whereBetween('r.committed_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('r.promotion_id', 'r.promotion_name_snapshot')
            ->selectRaw('r.promotion_id, r.promotion_name_snapshot as name, COUNT(*) as redemptions, SUM(r.base_discount_amount) as discount')
            ->orderByDesc('redemptions')
            ->limit(TableBlock::MAX_ROWS)
            ->get()
            ->map(static fn ($r): array => [
                'promotion_id' => (int) $r->promotion_id,
                'name' => $r->name,
                'redemptions' => (int) $r->redemptions,
                'discount' => bcadd((string) $r->discount, '0', 4),
                'currency_code' => $currency,
            ])->all();

        return new TableBlock('top_promotions', 'Top promotions', [
            ['key' => 'name', 'label' => 'Promotion', 'format' => 'text'],
            ['key' => 'redemptions', 'label' => 'Redemptions', 'format' => KpiValue::COUNT],
            ['key' => 'discount', 'label' => 'Discount', 'format' => 'money'],
        ], $rows, '/admin/promotions');
    }

    /**
     * Committed redemptions of live, scoped orders.
     */
    private function redemptions(MetricsScope $scope): Builder
    {
        return $scope->orders(DB::connection('tenant')->table('promotion_redemptions as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.status', PromotionRedemption::COMMITTED)
            ->where('o.is_test', false));
    }

    private function currency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }
}
