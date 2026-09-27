<?php

declare(strict_types=1);

namespace App\Modules\Payments\Metrics;

use App\Modules\Payments\Models\OrderPayment;
use App\Modules\Settings\Services\TenantSettingsService;
use App\Shared\Metrics\Alert;
use App\Shared\Metrics\ChartSeries;
use App\Shared\Metrics\DateRange;
use App\Shared\Metrics\KpiValue;
use App\Shared\Metrics\MetricsScope;
use App\Shared\Metrics\SectionResult;
use App\Shared\Metrics\TableBlock;
use App\Shared\Metrics\TimeSeries;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Order payment figures (spec §44.2 "Payments"): live payment rows of live
 * orders created in the range, by status; refunds dated by paid_at; the
 * gateway success rate over decided gateway payments.
 */
final readonly class PaymentMetrics
{
    /** Failed gateway payments in 24 hours that raise an alert, and their minimum share. */
    private const int SPIKE_MIN_FAILURES = 5;

    private const string SPIKE_MIN_RATE = '30';

    public function __construct(private TenantSettingsService $settings) {}

    public function payments(DateRange $range, MetricsScope $scope): SectionResult
    {
        $byStatus = TimeSeries::aggregate($this->paymentRows($scope), 'op.created_at', $range, 'COUNT(*)', 'op.status');
        $empty = array_fill_keys($range->buckets(), '0');
        $comparison = $range->comparison();
        $previous = $comparison === null ? null : TimeSeries::total($this->paymentRows($scope), 'op.created_at', $comparison, 'COUNT(*)', 'op.status');
        $prev = static fn (string $status): ?int => $previous === null ? null : (int) ($previous[$status] ?? 0);
        $refunds = $this->refunds($range, $scope);
        $rate = $this->gatewayRate($range, $scope);
        $prevRate = $comparison === null ? null : $this->gatewayRate($comparison, $scope);

        return new SectionResult(
            kpis: [
                KpiValue::count('successful_payments', 'Successful', (int) TimeSeries::sum($byStatus[OrderPayment::SUCCESSFUL] ?? $empty), $range,
                    $prev(OrderPayment::SUCCESSFUL), KpiValue::UP_IS_GOOD, TimeSeries::sparkline($byStatus[OrderPayment::SUCCESSFUL] ?? $empty, true)),
                KpiValue::count('failed_payments', 'Failed', (int) TimeSeries::sum($byStatus[OrderPayment::FAILED] ?? $empty), $range, $prev(OrderPayment::FAILED), KpiValue::DOWN_IS_GOOD),
                KpiValue::count('pending_payments', 'Pending', (int) TimeSeries::sum($byStatus[OrderPayment::PENDING] ?? $empty), $range, $prev(OrderPayment::PENDING), KpiValue::NEUTRAL),
                KpiValue::money('refunds', 'Refunds', $refunds['amount'], $this->currency(), $range,
                    $comparison === null ? null : $this->refunds($comparison, $scope)['amount'], KpiValue::DOWN_IS_GOOD),
                KpiValue::rate('gateway_success_rate', 'Gateway success rate', $rate[0], $rate[1], $range, $prevRate),
            ],
            charts: [$this->volumeBy('provider', $range, $scope), $this->volumeBy('payment_method', $range, $scope)],
            tables: [$this->recentFailures($scope)],
        );
    }

    /**
     * The order-payments list KPI strip (§44.4).
     *
     * @return list<KpiValue>
     */
    public function contextual(DateRange $range, MetricsScope $scope): array
    {
        $comparison = $range->comparison();
        $current = TimeSeries::total($this->paymentRows($scope), 'op.created_at', $range, 'COUNT(*)', 'op.status');
        $previous = $comparison === null ? null : TimeSeries::total($this->paymentRows($scope), 'op.created_at', $comparison, 'COUNT(*)', 'op.status');
        $count = static fn (?array $rows, string $status): ?int => $rows === null ? null : (int) ($rows[$status] ?? 0);
        $collected = fn (DateRange $r): string => bcadd((string) ($this->paymentRows($scope)->where('op.status', OrderPayment::SUCCESSFUL)
            ->whereBetween('op.paid_at', [$r->startUtc(), $r->endUtc()])
            ->value(DB::raw('SUM(op.amount_paid * COALESCE(op.exchange_rate_used, 1))')) ?? '0'), '0', 4);

        return [
            KpiValue::count('successful_payments', 'Successful', (int) $count($current, OrderPayment::SUCCESSFUL), $range, $count($previous, OrderPayment::SUCCESSFUL)),
            KpiValue::count('pending_payments', 'Pending', (int) $count($current, OrderPayment::PENDING), $range, $count($previous, OrderPayment::PENDING), KpiValue::NEUTRAL),
            KpiValue::count('failed_payments', 'Failed', (int) $count($current, OrderPayment::FAILED), $range, $count($previous, OrderPayment::FAILED), KpiValue::DOWN_IS_GOOD),
            KpiValue::count('refunds', 'Refunded', $this->refunds($range, $scope)['count'], $range, $comparison === null ? null : $this->refunds($comparison, $scope)['count'], KpiValue::DOWN_IS_GOOD),
            KpiValue::money('total_collected', 'Total collected', $collected($range), $this->currency(), $range, $comparison === null ? null : $collected($comparison)),
        ];
    }

    /**
     * @return list<Alert>
     */
    public function alerts(MetricsScope $scope): array
    {
        $row = $this->paymentRows($scope)
            ->where('op.payment_method', 'gateway')
            ->whereIn('op.status', [OrderPayment::SUCCESSFUL, OrderPayment::FAILED])
            ->where('op.created_at', '>=', now()->subDay())
            ->selectRaw("SUM(CASE WHEN op.status = 'failed' THEN 1 ELSE 0 END) as failed, COUNT(*) as decided")
            ->first();

        $failed = (int) ($row->failed ?? 0);
        $rate = KpiValue::percentOf((string) $failed, (string) ($row->decided ?? 0));

        if ($failed < self::SPIKE_MIN_FAILURES || $rate === null || bccomp($rate, self::SPIKE_MIN_RATE, 1) < 0) {
            return [];
        }

        return [new Alert('payment_failure_spike', Alert::CRITICAL, "{$failed} of {$row->decided} online payments failed ({$rate}%) in the last 24 hours.", $failed, '/admin/order-payments?status=failed')];
    }

    /**
     * @return array{count: int, amount: string}
     */
    private function refunds(DateRange $range, MetricsScope $scope): array
    {
        $row = $scope->orders(DB::connection('tenant')->table('order_payments as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->where('op.kind', OrderPayment::REFUND)->where('op.status', OrderPayment::SUCCESSFUL)->where('op.mode', 'live')->where('o.is_test', false)
            ->whereBetween('op.paid_at', [$range->startUtc(), $range->endUtc()]))
            ->selectRaw('COUNT(*) as refunds, SUM(-op.amount_paid * COALESCE(op.exchange_rate_used, o.exchange_rate_used, 1)) as amount')
            ->first();

        return ['count' => (int) ($row->refunds ?? 0), 'amount' => bcadd((string) ($row->amount ?? '0'), '0', 4)];
    }

    /**
     * @return array{0: string, 1: string} successful, decided
     */
    private function gatewayRate(DateRange $range, MetricsScope $scope): array
    {
        $counts = TimeSeries::total($this->paymentRows($scope)->where('op.payment_method', 'gateway'), 'op.created_at', $range, 'COUNT(*)', 'op.status');
        $successful = (int) ($counts[OrderPayment::SUCCESSFUL] ?? 0);

        return [(string) $successful, (string) ($successful + (int) ($counts[OrderPayment::FAILED] ?? 0))];
    }

    private function volumeBy(string $column, DateRange $range, MetricsScope $scope): ChartSeries
    {
        $rows = $this->paymentRows($scope)
            ->whereBetween('op.created_at', [$range->startUtc(), $range->endUtc()])
            ->groupByRaw("COALESCE(op.{$column}, 'manual'), op.status")
            ->selectRaw("COALESCE(op.{$column}, 'manual') as label, op.status, COUNT(*) as aggregate")
            ->get();

        $labels = $rows->pluck('label')->unique()->sort()->values()->all();
        $series = [];

        foreach ([OrderPayment::SUCCESSFUL, OrderPayment::FAILED, OrderPayment::PENDING] as $status) {
            $series[] = [
                'key' => $status,
                'label' => ucfirst($status),
                'points' => array_map(static fn (string $label): array => [
                    'x' => $label,
                    'y' => (int) ($rows->first(static fn ($r): bool => $r->label === $label && $r->status === $status)->aggregate ?? 0),
                ], $labels),
            ];
        }

        $key = $column === 'provider' ? 'volume_by_provider' : 'volume_by_method';

        return new ChartSeries($key, $column === 'provider' ? 'Payments by provider' : 'Payments by method', ChartSeries::STACKED_BAR, KpiValue::COUNT, $series);
    }

    private function recentFailures(MetricsScope $scope): TableBlock
    {
        $rows = $this->paymentRows($scope)
            ->where('op.status', OrderPayment::FAILED)
            ->orderByDesc('op.created_at')
            ->limit(TableBlock::MAX_ROWS)
            ->get(['op.id', 'o.order_number', 'op.amount_paid', 'op.amount_due', 'op.currency_code', 'op.provider', 'op.payment_method', 'op.created_at'])
            ->map(static fn ($r): array => [
                'id' => (int) $r->id,
                'order_number' => $r->order_number,
                'amount' => bcadd((string) ($r->amount_due ?? $r->amount_paid), '0', 4),
                'currency_code' => $r->currency_code,
                'provider' => $r->provider ?? $r->payment_method,
                'created_at' => CarbonImmutable::parse($r->created_at, 'UTC')->toIso8601String(),
            ])->all();

        return new TableBlock('recent_failed_payments', 'Recent failed payments', [
            ['key' => 'order_number', 'label' => 'Order', 'format' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
            ['key' => 'provider', 'label' => 'Provider', 'format' => 'text'],
            ['key' => 'created_at', 'label' => 'Attempted', 'format' => 'datetime'],
        ], $rows, '/admin/order-payments?status=failed');
    }

    /**
     * Live payment rows (kind = payment) of live, scoped orders.
     */
    private function paymentRows(MetricsScope $scope): Builder
    {
        return $scope->orders(DB::connection('tenant')->table('order_payments as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->where('op.kind', OrderPayment::PAYMENT)
            ->where('op.mode', 'live')
            ->where('o.is_test', false));
    }

    private function currency(): string
    {
        return strtoupper((string) ($this->settings->get('default_currency') ?: 'USD'));
    }
}
