<?php

declare(strict_types=1);

namespace App\Modules\Returns\Metrics;

use App\Modules\Orders\Metrics\OrderQueries;
use App\Modules\Returns\Models\OrderReturn;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use App\Shared\Metrics\TimeSeries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Return figures (spec §44.3 "returns"): requests by requested_at; awaiting
 * staff action now (a decision on a request, or a refund or exchange for
 * a received return); the return rate against included orders; refund
 * value from the return-linked refund rows by paid_at.
 */
final readonly class ReturnMetrics
{
    /** Statuses where the store must act next. */
    private const array AWAITING_ACTION = [OrderReturn::REQUESTED, OrderReturn::RECEIVED];

    public function __construct(private TenantSettingsService $settings) {}

    public function returns(DateRange $range, MetricsScope $scope): SectionResult
    {
        $requested = TimeSeries::aggregate($this->returnsQuery($scope), 'r.requested_at', $range, 'COUNT(*)')[''] ?? array_fill_keys($range->buckets(), '0');
        $comparison = $range->comparison();
        $prevRequested = $comparison === null ? null : $this->requestedCount($comparison, $scope);
        $orders = (string) OrderQueries::included($scope)->whereBetween('o.confirmed_at', [$range->startUtc(), $range->endUtc()])->count();
        $prevOrders = $comparison === null ? null : (string) OrderQueries::included($scope)->whereBetween('o.confirmed_at', [$comparison->startUtc(), $comparison->endUtc()])->count();

        return new SectionResult(
            kpis: [
                KpiValue::count('returns_requested', 'Requested', (int) TimeSeries::sum($requested), $range, $prevRequested, KpiValue::DOWN_IS_GOOD, TimeSeries::sparkline($requested, true)),
                KpiValue::count('returns_awaiting_action', 'Awaiting action', $this->awaitingAction($scope), $range, null, KpiValue::DOWN_IS_GOOD),
                KpiValue::rate('return_rate', 'Return rate', TimeSeries::sum($requested), $orders, $range,
                    $prevRequested === null ? null : [(string) $prevRequested, (string) $prevOrders], KpiValue::DOWN_IS_GOOD),
                KpiValue::money('refund_value', 'Refund value', $this->refundValue($range, $scope), $this->currency(), $range,
                    $comparison === null ? null : $this->refundValue($comparison, $scope), KpiValue::DOWN_IS_GOOD),
            ],
            tables: [$this->topReasons($range, $scope)],
        );
    }

    /**
     * The returns list KPI strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        $comparison = $range->comparison();
        $refunded = fn (DateRange $r): int => $this->returnsQuery($scope)->where('r.status', OrderReturn::REFUNDED)
            ->whereBetween('r.resolved_at', [$r->startUtc(), $r->endUtc()])->count();

        return [
            KpiValue::count('returns_requested', 'Requested', $this->requestedCount($range, $scope), $range, $comparison === null ? null : $this->requestedCount($comparison, $scope), KpiValue::DOWN_IS_GOOD),
            KpiValue::count('returns_awaiting_action', 'Awaiting action', $this->awaitingAction($scope), $range, null, KpiValue::DOWN_IS_GOOD),
            KpiValue::count('returns_refunded', 'Refunded', $refunded($range), $range, $comparison === null ? null : $refunded($comparison), KpiValue::NEUTRAL),
            KpiValue::money('refund_value', 'Refund value', $this->refundValue($range, $scope), $this->currency(), $range,
                $comparison === null ? null : $this->refundValue($comparison, $scope), KpiValue::DOWN_IS_GOOD),
        ];
    }

    /**
     * @return list<Alert>
     */
    public function alerts(MetricsScope $scope): array
    {
        $count = $this->awaitingAction($scope);

        return $count === 0 ? [] : [new Alert('returns_awaiting_action', Alert::INFO,
            "{$count} ".($count === 1 ? 'return needs' : 'returns need').' your action.', $count, '/admin/returns?status=requested')];
    }

    private function requestedCount(DateRange $range, MetricsScope $scope): int
    {
        return (int) (TimeSeries::total($this->returnsQuery($scope), 'r.requested_at', $range, 'COUNT(*)')[''] ?? 0);
    }

    private function awaitingAction(MetricsScope $scope): int
    {
        return $this->returnsQuery($scope)->whereIn('r.status', self::AWAITING_ACTION)->count();
    }

    private function refundValue(DateRange $range, MetricsScope $scope): string
    {
        $value = $scope->orders(DB::connection('tenant')->table('order_payments as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->whereNotNull('op.order_return_id')
            ->where('op.kind', 'refund')->where('op.status', 'successful')->where('op.mode', 'live')->where('o.is_test', false)
            ->whereBetween('op.paid_at', [$range->startUtc(), $range->endUtc()]))
            ->value(DB::raw('SUM(-op.amount_paid * COALESCE(op.exchange_rate_used, o.exchange_rate_used, 1))'));

        return bcadd((string) ($value ?? '0'), '0', 4);
    }

    private function topReasons(DateRange $range, MetricsScope $scope): TableBlock
    {
        $rows = $this->returnsQuery($scope)
            ->leftJoin('return_reasons as rr', 'rr.id', '=', 'r.return_reason_id')
            ->whereBetween('r.requested_at', [$range->startUtc(), $range->endUtc()])
            ->groupBy('rr.id', 'rr.label')
            ->selectRaw("rr.id, COALESCE(rr.label, 'Other') as label, COUNT(*) as returns")
            ->orderByDesc('returns')
            ->limit(TableBlock::MAX_ROWS)
            ->get()
            ->map(static fn ($r): array => ['reason_id' => $r->id === null ? null : (int) $r->id, 'reason' => $r->label, 'returns' => (int) $r->returns])
            ->all();

        return new TableBlock('top_return_reasons', 'Top return reasons', [
            ['key' => 'reason', 'label' => 'Reason', 'format' => 'text'],
            ['key' => 'returns', 'label' => 'Returns', 'format' => KpiValue::COUNT],
        ], $rows, '/admin/returns');
    }

    /**
     * Returns of live, scoped orders.
     */
    private function returnsQuery(MetricsScope $scope): Builder
    {
        return $scope->orders(DB::connection('tenant')->table('order_returns as r')
            ->join('orders as o', 'o.id', '=', 'r.order_id')
            ->where('o.is_test', false));
    }

    private function currency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }
}
